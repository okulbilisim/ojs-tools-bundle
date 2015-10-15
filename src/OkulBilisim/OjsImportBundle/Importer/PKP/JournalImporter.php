<?php

namespace OkulBilisim\OjsImportBundle\Importer\PKP;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Ojs\JournalBundle\Entity\Journal;
use Ojs\JournalBundle\Entity\JournalTranslation;
use Ojs\JournalBundle\Entity\Lang;
use Ojs\JournalBundle\Entity\Publisher;
use Ojs\JournalBundle\Entity\PublisherTranslation;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

class JournalImporter extends Importer
{
    /**
     * @var Journal
     */
    private $journal;

    /**
     * @var array
     */
    private $settings;

    /**
     * @var UserImporter
     */
    private $userImporter;

    /**
     * @var SectionImporter
     */
    private $sectionImporter;

    /**
     * @var IssueImporter
     */
    private $issueImporter;

    /**
     * @var ArticleImporter
     */
    private $articleImporter;

    /**
     * JournalImporter constructor.
     * @param Connection $connection
     * @param EntityManager $em
     * @param OutputInterface $consoleOutput
     * @param UserImporter $ui
     */
    public function __construct(Connection $connection, EntityManager $em, OutputInterface $consoleOutput, UserImporter $ui)
    {
        parent::__construct($connection, $em, $consoleOutput);

        $this->userImporter = $ui;
        $this->sectionImporter = new SectionImporter($this->connection, $this->em, $consoleOutput);
        $this->issueImporter = new IssueImporter($this->connection, $this->em, $consoleOutput);
        $this->articleImporter = new ArticleImporter($this->connection, $this->em, $consoleOutput, $this->userImporter);
    }

    public function importJournal($id)
    {
        $this->consoleOutput->writeln("Importing the journal...");

        $journalSql = "SELECT path, primary_locale FROM journals WHERE journal_id = :id LIMIT 1";
        $journalStatement = $this->connection->prepare($journalSql);
        $journalStatement->bindValue('id', $id);
        $journalStatement->execute();

        $settingsSql = "SELECT locale, setting_name, setting_value FROM journal_settings WHERE journal_id = :id";
        $settingsStatement = $this->connection->prepare($settingsSql);
        $settingsStatement->bindValue('id', $id);
        $settingsStatement->execute();

        $pkpJournal = $journalStatement->fetch();
        $pkpSettings = $settingsStatement->fetchAll();
        $primaryLocale = $pkpJournal['primary_locale'];
        $languageCode = substr($primaryLocale, 0, 2);

        !$pkpJournal && die('Journal not found.' . PHP_EOL);
        $this->consoleOutput->writeln("Reading journal settings...");

        foreach ($pkpSettings as $setting) {
            $locale = !empty($setting['locale']) ? $setting['locale'] : $primaryLocale;
            $name = $setting['setting_name'];
            $value = $setting['setting_value'];
            $this->settings[$locale][$name] = $value;
        }

        $this->journal = new Journal();
        $this->journal->setStatus(1);
        $this->journal->setPublished(true);
        $this->journal->setSlug($pkpJournal['path']);

        // Fill translatable fields in all available languages
        foreach ($this->settings as $fieldLocale => $fields) {
            $translation = new JournalTranslation();
            $translation->setLocale(substr($fieldLocale, 0, 2));
            $this->journal->setCurrentLocale(substr($fieldLocale, 0, 2));

            !empty($fields['title']) ?
                $translation->setTitle($fields['title']) :
                $translation->setTitle('Unknown Journal');

            !empty($fields['description']) ?
                $translation->setDescription($fields['description']) :
                $translation->setDescription('-');

            $this->journal->addTranslation($translation);
        }

        $this->journal->setCurrentLocale($primaryLocale);

        !empty($this->settings[$primaryLocale]['printIssn']) ?
            $this->journal->setIssn($this->settings[$primaryLocale]['printIssn']) :
            $this->journal->setIssn('1234-5679');

        !empty($this->settings[$primaryLocale]['onlineIssn']) ?
            $this->journal->setEissn($this->settings[$primaryLocale]['onlineIssn']) :
            $this->journal->setEissn('1234-5679');

        $date = sprintf('%d-01-01 00:00:00',
            !empty($this->settings[$primaryLocale]['initialYear']) ?
                $this->settings[$primaryLocale]['initialYear'] : '2015');
        $this->journal->setFounded(DateTime::createFromFormat('Y-m-d H:i:s', $date));

        // Set publisher
        !empty($this->settings[$primaryLocale]['publisherInstitution']) ?
            $this->importAndSetPublisher($this->settings[$primaryLocale]['publisherInstitution'], $primaryLocale) :
            $this->journal->setPublisher($this->getUnknownPublisher());

        // Use existing languages or create if needed
        $language = $this->em
            ->getRepository('OjsJournalBundle:Lang')
            ->findOneBy(['code' => $languageCode]);
        $this->journal->setMandatoryLang($language ? $language : $this->createLanguage($languageCode));
        $this->journal->addLanguage($language ? $language : $this->createLanguage($languageCode));

        $this->consoleOutput->writeln("Read journal's settings.");

        $createdSections = $this->sectionImporter->importJournalsSections($this->journal, $id);
        $createdIssues = $this->issueImporter->importJournalsIssues($this->journal, $id, $createdSections);
        $this->articleImporter->importArticles($id, $this->journal, $createdIssues, $createdSections);

        $this->em->persist($this->journal);

        $this->consoleOutput->writeln("Writing data...");
        $this->em->flush();
        $this->consoleOutput->writeln("Imported journal.");

        return ['new' => $this->journal->getId(), 'old' => $id];
    }

    private function importAndSetPublisher($name, $locale)
    {
        $publisher = $this->em
            ->getRepository('OjsJournalBundle:Publisher')
            ->findOneBy(['name' => $name]);

        if (!$publisher) {
            $url = !empty($this->settings[$locale]['publisherUrl']) ? $this->settings[$locale]['publisherUrl'] : null;
            $publisher = $this->createPublisher($this->settings[$locale]['publisherInstitution'], $url);

            foreach ($this->settings as $fieldLocale => $fields) {
                $translation = new PublisherTranslation();
                $translation->setLocale(substr($fieldLocale, 0, 2));

                !empty($fields['publisherNote']) ?
                    $translation->setAbout($fields['publisherNote']) :
                    $translation->setAbout('-');

                $publisher->addTranslation($translation);
            }
        }

        $this->journal->setPublisher($publisher);
    }

    private function getUnknownPublisher()
    {
        $publisher = $this->em
            ->getRepository('OjsJournalBundle:Publisher')
            ->findOneBy(['name' => 'Unknown Publisher']);

        !$publisher && $publisher = $this->createPublisher('Unknown Publisher', 'http://example.com');
        $publisher->setCurrentLocale('en');
        $publisher->setAbout('-');

        $this->em->persist($publisher);

        return $publisher;
    }

    private function createPublisher($name, $url)
    {
        $publisher = new Publisher();
        $publisher->setName($name);
        $publisher->setEmail('publisher@example.com');
        $publisher->setAddress('-');
        $publisher->setPhone('-');
        $publisher->setUrl($url);

        $this->em->persist($publisher);

        return $publisher;
    }

    private function createLanguage($code)
    {
        $nameMap = array(
            'tr' => 'Türkçe',
            'en' => 'English',
            'de' => 'Deutsch',
            'fr' => 'Français',
            'ru' => 'Русский язык',
        );

        $lang = new Lang();
        $lang->setCode($code);
        !empty($nameMap[$code]) ?
            $lang->setName($nameMap[$code]) :
            $lang->setName('Unknown Language');

        $this->em->persist($lang);

        return $lang;
    }
}