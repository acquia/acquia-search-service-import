<?php

namespace Acquia\Search\Import\Command;

use FilesystemIterator;
use PharData;
use PSolr\Client\SolrClient;
use RecursiveDirectoryIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Acquia\Network\AcquiaNetworkClient;
use Acquia\Search\AcquiaSearchService;
use Acquia\Common\AcquiaServiceManager;


class ImportCommand extends Command {

  /**
   * @var ConsoleLogger $logger
   */
  private $logger;

  protected function configure() {
    $this
      ->setName('import')
      ->setDescription('Import a folder of exported Solr documents or tarball into an Acquia Search Index.')
      ->addArgument(
        'index',
        InputArgument::REQUIRED,
        'The full name of the index to import to. Eg.: ABCD-12345.'
      )
      ->addArgument(
        'path',
        InputArgument::REQUIRED,
        'the full path where the export was saved. This path should exist. Eg. /tmp/as_export/ABCD-12345. Can be a folder with xml files or a tarball.'
      )
      ->addOption(
        'tmp',
        't',
        InputOption::VALUE_REQUIRED,
        'The tmp folder to use.',
        '/tmp/as_import_tmp'
      )
    ;
  }

  protected function execute(InputInterface $input, OutputInterface $output) {

    $index_given = $input->getArgument('index');
    $path = $input->getArgument('path');
    $tmp_path = $input->getOption('tmp');

    $verbosityLevelMap = array(
      'notice' => OutputInterface::VERBOSITY_NORMAL,
      'info' => OutputInterface::VERBOSITY_NORMAL,
    );
    $this->logger = new ConsoleLogger($output, $verbosityLevelMap);

    // Get the Acquia Network Subscription
    $subscription = $this->getSubscription($output);

    $this->logger->info('Checking if the given subscription has Acquia Search indexes...');
    if (!empty($subscription['heartbeat_data']['search_cores']) && is_array($subscription['heartbeat_data']['search_cores'])) {
      $search_cores = $subscription['heartbeat_data']['search_cores'];
    }
    else {
      $this->logger->error('No Search Cores found for given subscription');
      exit();
    }

    /** @var \Symfony\Component\Console\Helper\DialogHelper $dialog */
    $dialog = $this->getHelperSet()->get('dialog');
    $delete_all_content = $dialog->askConfirmation($output, 'Do you want to continue? (y/n). This will DELETE all contents from the ' . $index_given . ' index! ... : ', false);

    if (!$delete_all_content) {
      exit(1);
    }

    // Loop through each cores.
    foreach ($search_cores as $search_core) {
      $search_core_identifier = $search_core['core_id'];
      // Go over all search indexes given by NSPI and execute our tasks with the
      // correct one.
      if (isset($index_given) && $search_core_identifier !== $index_given) {
        continue;
      }

      // A subscription can have multiple indexes. The Acquia Search service
      // builder generates credentials and clients for all of the
      // subscription's indexes.
      $search = AcquiaSearchService::factory($subscription);

      /** @var \PSolr\Client\SolrClient $index */
      $index = $search->get($search_core_identifier);

      if (!file_exists($tmp_path)) {
        mkdir($tmp_path, 0700);
        $this->logger->info('Created the ' . $tmp_path . ' directory');
      }

      if (!file_exists($tmp_path .'/' . $search_core_identifier)) {
        mkdir($tmp_path .'/' . $search_core_identifier, 0700);
        $this->logger->info('Created the ' . $tmp_path .'/' . $search_core_identifier . ' directory');
      }

      $pathinfo = pathinfo($path);

      // If given path is a tarball, extract in our tmp directory
      if (!is_dir($path) && substr(strrchr($path, "."), 1) == 'gz') {
        // Clear the tmp directory
        $this->logger->info('Clearing the ' . $tmp_path .'/' . $search_core_identifier . ' directory');
        $directory_iterator = new RecursiveDirectoryIterator($tmp_path .'/' . $search_core_identifier, FilesystemIterator::SKIP_DOTS);
        foreach ($directory_iterator as $file) {
          unlink($file);
        }
        // Extract our data
        $this->logger->info('Using ' . $path);
        try {
          $phar = new PharData($path);
          $phar->extractTo($tmp_path .'/' . $search_core_identifier); // extract all files
          // Set our path to import from to the tmp path
          $path = $tmp_path .'/' . $search_core_identifier;
        }
        catch (\Exception $e) {
          $this->logger->error('Could not extract ' . $path . '.' . $e->getMessage());
        }
      }

      $count = 0;
      $total_count = 0;
      $send_per_time = 200;
      $file_amount = count(glob($path . "/*.{xml}", GLOB_BRACE));

      $data = "";

      // Delete all contents from a specified index
      $this->deleteIndex($index, $search_core_identifier);

      // Loop over the folder and send in batches
      foreach (glob($path . "/*.{xml}", GLOB_BRACE) as $solr_document) {
        // Add the add variables and send up to "send_per_time" documents
        if ($count == 0) {
          $data = "<add>";
        }

        // Read in the solr document
        $data .= file_get_contents($solr_document);
        $total_count++;
        $count++;
        if ($count === $send_per_time || $total_count >= $file_amount) {
          $data .= "</add>";
          // Send the data
          $response = $index->post('update', array("Content-Type: text/xml"), $data)->send();
          if ($response->getStatusCode() !== 200) {
            $this->logger->error('Index ' . $search_core_identifier . ' did not confirm on the submission of the items.');
          }
          $this->logger->info("Sent " . $total_count . "/" . $file_amount . " documents to " . $index_given);
          $count = 0;
        }

      }
      // Also commit the last documents forcefully so we are done
      $response = $index->post('update', array("Content-Type: text/xml"), "<commit/>")->send();
      if ($response->getStatusCode() !== 200) {
        $this->logger->error('Index ' . $search_core_identifier . ' did not confirm on the submission of the items.');
      }
      $this->logger->info("Sent " . $total_count . " documents to " . $index_given);

      $response = $index->get('/admin/luke?wt=json&numTerms=0')->send()->json();
      $this->logger->info('Index ' . $search_core_identifier . ' has ' . $response['index']['numDocs'] . ' items in the index.');
    }

  }

  /**
   * @param \PSolr\Client\SolrClient $index
   */
  public function deleteIndex(SolrClient $index, $search_core_identifier) {
    // Delete all documents in the index
    $response = $index->post('update', array("Content-Type: text/xml"), "<delete><query>*:*</query></delete>")->send();
    if ($response->getStatusCode() !== 200) {
      $this->logger->error('Index ' . $search_core_identifier . ' did not confirm on the deletion of the items.');
    }
    // Force the deletion
    $response = $index->post('update', array("Content-Type: text/xml"), "<commit/>")->send();

    $response = $index->get('/admin/luke?wt=json&numTerms=0')->send()->json();
    if (!isset($response['index']['numDocs']) || $response['index']['numDocs'] != 0) {
      $this->logger->error('Index ' . $search_core_identifier . ' still has ' . $response['index']['numDocs'] . ' items in the index');
      exit();
    }
    $this->logger->info('Index ' . $search_core_identifier . ' has ' . $response['index']['numDocs'] . ' items in the index. Proceeding...');
  }

  /**
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return \Acquia\Network\AcquiaNetworkClient
   */
  public function getSubscription(OutputInterface $output)
  {

    $services = new AcquiaServiceManager(array(
      'conf_dir' => $_SERVER['HOME'] . '/.Acquia/auth',
    ));

    $network = $services->getClient('network', 'network');
    if (!$network) {
      $config = $this->promptIdentity($output);
      $network = AcquiaNetworkClient::factory($config);
      $services->setClient('network', 'network', $network);
      $services->saveServiceGroup('network');
    }
    // Get the subscription
    $subscription = $network->checkSubscription();

    return $subscription;
  }

  /**
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return array
   */
  public function promptIdentity(OutputInterface $output)
  {
    /** @var \Symfony\Component\Console\Helper\DialogHelper $dialog */
    $dialog = $this->getHelperSet()->get('dialog');
    return array(
      'network_id' => $dialog->ask($output, 'Acquia Network ID: '),
      'network_key' => $dialog->askHiddenResponse($output, 'Acquia Network Key: '),
    );
  }
}