<?php

namespace Acquia\Search\Import\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Acquia\Network\AcquiaNetworkClient;
use Acquia\Search\AcquiaSearchService;
use Acquia\Common\AcquiaServiceManager;


class ImportCommand extends Command {
  protected function configure() {
    $this
      ->setName('import')
      ->setDescription('Import a folder of exported Solr documents into an Acquia Search Index.')
      ->addOption(
        'index',
        'r',
        InputOption::VALUE_REQUIRED,
        'The full name of the index to import to. Eg.: ABCD-12345.'
      )
      ->addOption(
        'path',
        'p',
        InputOption::VALUE_REQUIRED,
        'the full path where the export was saved. This path should exist.',
        '/tmp/search_export/ABCD-1234'
      )
    ;
  }

  protected function execute(InputInterface $input, OutputInterface $output) {

    $index_given = $input->getOption('index');
    $path = $input->getOption('path');

    $verbosityLevelMap = array(
      'notice' => OutputInterface::VERBOSITY_NORMAL,
      'info' => OutputInterface::VERBOSITY_NORMAL,
    );
    $logger = new ConsoleLogger($output, $verbosityLevelMap);

    // Get the Acquia Network Subscription
    $subscription = $this->getSubscription($output);

    $logger->info('Checking if the given subscription has Acquia Search indexes...');
    if (!empty($subscription['heartbeat_data']['search_cores']) && is_array($subscription['heartbeat_data']['search_cores'])) {
      $search_cores = $subscription['heartbeat_data']['search_cores'];
      $count = count($subscription['heartbeat_data']['search_cores']);
      $logger->info('Found ' . $count . ' Acquia Search indexes.');
    }
    else {
      $logger->error('No Search Cores found for given subscription');
      exit();
    }

    /** @var \Symfony\Component\Console\Helper\DialogHelper $dialog */
    $dialog = $this->getHelperSet()->get('dialog');
    $dialog->askConfirmation($output, 'Do you want to continue? (y/n). This will DELETE all contents from the ' . $index_given . ' index! ... : ', false);

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

      // Delete all documents in the index
      $response = $index->post('update', array("Content-Type: text/xml"), "<delete><query>*:*</query></delete>")->send();
      if ($response->getStatusCode() !== 200) {
        $logger->error('Index ' . $search_core_identifier . ' did not confirm on the deletion of the items.');
      }
      // Force the deletion
      $response = $index->post('update', array("Content-Type: text/xml"), "<commit/>")->send();

      $response = $index->get('/admin/luke?wt=json&numTerms=0')->send()->json();
      if (!isset($response['index']['numDocs']) || $response['index']['numDocs'] != 0) {
        $logger->error('Index ' . $search_core_identifier . ' still has ' . $response['index']['numDocs'] . ' items in the index');
        exit();
      }
      $logger->info('Index ' . $search_core_identifier . ' has ' . $response['index']['numDocs'] . ' items in the index. Proceeding...');

      $count = 0;
      $total_count = 0;
      $send_per_time = 200;
      $file_amount = count(glob($path . "/*.{xml}", GLOB_BRACE));

      $data = "";

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
            $logger->error('Index ' . $search_core_identifier . ' did not confirm on the submission of the items.');
          }
          $logger->info("Sent " . $total_count . "/" . $file_amount . " documents to " . $index_given);
          $count = 0;
        }

      }
      // Also commit the last documents forcefully so we are done
      $response = $index->post('update', array("Content-Type: text/xml"), "<commit/>")->send();
      if ($response->getStatusCode() !== 200) {
        $logger->error('Index ' . $search_core_identifier . ' did not confirm on the submission of the items.');
      }
      $logger->info("Sent " . $total_count . " documents to " . $index_given);

      $response = $index->get('/admin/luke?wt=json&numTerms=0')->send()->json();
      $logger->info('Index ' . $search_core_identifier . ' has ' . $response['index']['numDocs'] . ' items in the index.');
    }

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