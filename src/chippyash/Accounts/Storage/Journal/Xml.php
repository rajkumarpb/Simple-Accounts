<?php
/**
 * Simple Double Entry Accounting
 *
 * @author Ashley Kitson
 * @copyright Ashley Kitson, 2015, UK
 * @license GPL V3+ See LICENSE.md
 */
namespace chippyash\Accounts\Storage\Journal;

use chippyash\Accounts\AccountsException;
use chippyash\Accounts\Journal;
use chippyash\Accounts\JournalStorageInterface;
use chippyash\Accounts\Nominal;
use chippyash\Accounts\Transaction;
use chippyash\Currency\Factory as CurrencyFactory;
use chippyash\Type\Number\IntType;
use chippyash\Type\String\StringType;

/**
 * Xml File Storage for a Journal
 */
class Xml implements JournalStorageInterface
{
    /**
     * Path to journal storage file
     * @var StringType
     */
    protected $filePath;

    /**
     * Normalized path to journal file
     * @var StringType
     */
    protected $journalPath;

    /**
     * Template for new journal definition xml file
     * @var string
     */
    protected $template = <<<EOT
<?xml version="1.0"?>
<journal>
    <definition name="" crcy="GBP" inc="0"/>
    <transactions/>
</journal>
EOT;

    /**
     * @var StringType
     */
    protected $journalName;

    /**
     * Constructor
     *
     * @param StringType $filePath
     * @param StringType $journalName
     */
    public function __construct(StringType $filePath, StringType $journalName = null)
    {
        $this->filePath = $filePath;
        if (!is_null($journalName)) {
            $this->setJournalName($journalName);
        }
    }

    /**
     * Set the journal that we will next be working with
     *
     * @param StringType $name
     *
     * @return $this
     */
    public function setJournalName(StringType $name)
    {
        $this->journalName = $name;
        $this->normalizeFilePath($name);

        return $this;
    }

    /**
     * Write Journal definition to store
     * side effect: will set current journal
     *
     * @param Journal $journal
     *
     * @return bool
     */
    public function writeJournal(Journal $journal)
    {
        $this->setJournalName($journal->getName());
        if (file_exists($this->journalPath->get())) {
            return $this->amendJournal($journal);
        } else {
            return $this->createJournal($journal);
        }
    }

    /**
     * Read journal definition from store
     *
     * @return Journal
     * @throws \chippyash\Accounts\AccountsException
     */
    public function readJournal()
    {
        if (!isset($this->journalName)) {
            throw new AccountsException('Missing Journal name');
        }
        if (!file_exists($this->journalPath->get())) {
            throw new AccountsException('Missing Journal file');
        }
        //check to make sure Journal is valid
        $attribs = $this->getDefinition();

        $crcy = CurrencyFactory::create($attribs->getNamedItem('crcy')->nodeValue);
        $journal = new Journal($this->journalName, $crcy, $this);

        return $journal;
    }

    /**
     * Write a transaction to store
     *
     * @param Transaction $transaction
     *
     * @return IntType Transaction Unique Id
     * @throws \chippyash\Accounts\AccountsException
     */
    public function writeTransaction(Transaction $transaction)
    {
        if (!isset($this->journalName)) {
            throw new AccountsException('Missing Journal name');
        }

        $dom = $this->getDom();
        $xpath = new \DOMXPath($dom);
        $def = $xpath->query('/journal/definition')->item(0);
        $attribs = $def->attributes;
        $txnId = (intval($attribs->getNamedItem('inc')->nodeValue)) + 1;
        $attribs->getNamedItem('inc')->nodeValue = $txnId;

        $transactions = $xpath->query('/journal/transactions')->item(0);
        $newTxn = $dom->createElement('transaction', $transaction->getNote()->get());
        $newTxn->setAttribute('id', $txnId);
        $newTxn->setAttribute('dr', $transaction->getDrAc()->get());
        $newTxn->setAttribute('cr', $transaction->getCrAc()->get());
        $newTxn->setAttribute('amount', $transaction->getAmount()->get());
        //NB - although we are looking for an ISO801 format to match xs:datetime
        //the W3C format actually matches xsd datetime. PHP ISO8601 does not
        $newTxn->setAttribute('date', $transaction->getDate()->format(\DateTime::W3C));
        $transactions->appendChild($newTxn);
        $dom->save($this->journalPath->get());

        return new IntType($txnId);
    }

    /**
     * Read a transaction from store
     *
     * @param IntType $id Transaction Unique Id
     *
     * @return Transaction|null
     * @throws \chippyash\Accounts\AccountsException
     */
    public function readTransaction(IntType $id)
    {
        if (!isset($this->journalName)) {
            throw new AccountsException('Missing Journal name');
        }

        $dom = $this->getDom();
        $xpath = new \DOMXPath($dom);
        
        $nodes = $xpath->query("/journal/transactions/transaction[@id='{$id}']");
        if ($nodes->length !== 1) {
            return null;
        }
        
        $txn = $nodes->item(0);

        $crcy = $xpath->query('/journal/definition')->item(0)->attributes->getNamedItem('crcy')->nodeValue;

        return $this->createTransactionFromElement($txn, $crcy);
    }

    /**
     * Return all transactions for an account from store
     *
     * @param Nominal $nominal Account Nominal code
     *
     * @return array[Transaction,...]
     */
    public function readTransactions(Nominal $nominal)
    {
        $dom = $this->getDom();
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query("/journal/transactions/transaction[@dr='{$nominal}' or @cr='{$nominal}']");
        if ($nodes->length === 0){
            return array();
        }

        $crcy = $xpath->query('/journal/definition')->item(0)->attributes->getNamedItem('crcy')->nodeValue;
        $transactions = array();

        foreach ($nodes as $node) {
            $transactions[] = $this->createTransactionFromElement($node, $crcy);
        }

        return $transactions;
    }

    /**
     * Create transactionn from dom element
     *
     * @param \DOMElement $txn
     * @param $crcyCode
     * @return \DOMElement
     */
    protected function createTransactionFromElement(\DOMElement $txn, $crcyCode)
    {
        $crcy = CurrencyFactory::create(
            $crcyCode
        );
        $crcy->set(intval($txn->attributes->getNamedItem('amount')->nodeValue));

        $transaction = new Transaction(
            new Nominal($txn->attributes->getNamedItem('dr')->nodeValue),
            new Nominal($txn->attributes->getNamedItem('cr')->nodeValue),
            $crcy,
            new StringType($txn->nodeValue),
            new \DateTime($txn->attributes->getNamedItem('date')->nodeValue)
        );

        $transaction->setId(new IntType($txn->attributes->getNamedItem('id')->nodeValue));

        return $transaction;
    }

    /**
     * Set the normalized journal file name
     * @param StringType $journalName
     */
    protected function normalizeFilePath(StringType $journalName)
    {
        $this->journalPath = new StringType($this->filePath . '/' . strtolower(str_replace(' ', '-', $journalName)) . '.xml');
    }

    /**
     * Amend existing journal definition
     *
     * @param Journal $journal
     * @return bool
     */
    protected function amendJournal(Journal $journal)
    {
        $dom = new \DOMDocument();
        $dom->load($this->journalPath->get());

        return $this->updateJournalContent($dom, $journal);
    }

    /**
     * Create a new journal definition
     *
     * @param Journal $journal
     * @return bool
     */
    protected function createJournal(Journal $journal)
    {
        $dom = new \DOMDocument();
        $dom->loadXML($this->template);

        return $this->updateJournalContent($dom, $journal);
    }

    /**
     * Update content of journal definition
     *
     * @param \DOMDocument $dom
     * @param Journal $journal
     * @return bool
     */
    protected function updateJournalContent(\DOMDocument $dom, Journal $journal)
    {
        $xpath = new \DOMXPath($dom);
        $defNode = $xpath->query('/journal/definition')->item(0);
        $attributes = $defNode->attributes;
        $attributes->getNamedItem('name')->nodeValue = $journal->getName()->get();
        $attributes->getNamedItem('crcy')->nodeValue = $journal->getCurrency()->getCode();

        return ($dom->save($this->journalPath->get()) !== false);
    }

    /**
     * Read and validate a journal definition
     *
     * @return \DOMNamedNodeMap
     */
    protected function getDefinition()
    {
        $xpath = new \DOMXPath($this->getDom());

        return $xpath->query('/journal/definition')->item(0)->attributes;
    }

    /**
     * Get journal definition as Dom
     *
     * @return \DOMDocument
     * @throws AccountsException
     */
    protected function getDom()
    {
        $dom = new \DOMDocument();
        $dom->load($this->journalPath->get());
        $schemaPath = realpath(__DIR__ . '/../../definitions/journal-definition.xsd');

        libxml_use_internal_errors(true);
        if (!$dom->schemaValidate($schemaPath)) {
            $err = libxml_get_last_error()->message;
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            throw new AccountsException('Definition does not validate: ' . $err);
        }

        libxml_use_internal_errors(false);

        return $dom;
    }
}