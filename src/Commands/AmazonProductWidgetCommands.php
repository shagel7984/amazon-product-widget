<?php

namespace Drupal\amazon_product_widget\Commands;

use Drupal\amazon_product_widget\ProductService;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drush\Commands\DrushCommands;

/**
 * Class AmazonProductWidgetCommands.
 *
 * Provides custom drush commands for queueing and updating product
 * information.
 *
 * @package Drupal\amazon_product_widget\Commands
 */
class AmazonProductWidgetCommands extends DrushCommands {

  /**
   * ProductService.
   *
   * @var \Drupal\amazon_product_widget\ProductService
   */
  protected $productService;

  /**
   * Queue.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * QueueWorkerManagerInterface.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueWorker;

  /**
   * AmazonProductWidgetCommands constructor.
   *
   * @param \Drupal\amazon_product_widget\ProductService $productService
   *   ProductService.
   * @param \Drupal\Core\Queue\QueueFactory $queue
   *   QueueFactory.
   * @param \Drupal\Core\Queue\QueueWorkerManagerInterface $queueWorker
   *   QueueWorkerManagerInterface.
   */
  public function __construct(ProductService $productService, QueueFactory $queue, QueueWorkerManagerInterface $queueWorker) {
    parent::__construct();
    $this->productService = $productService;
    $this->queue = $queue;
    $this->queueWorker = $queueWorker;
  }

  /**
   * Queues all products for renewal.
   *
   * @command apw:queue-product-renewal
   */
  public function queueProductRenewal() {
    $asins = amazon_product_widget_get_all_asins();

    if (!empty($asins)) {
      try {
        $this->productService->queueProductRenewal($asins);
        $count = count($asins);
        $this->output()->writeln("$count products have been queued for renewal.");
      }
      catch (\Exception $exception) {
        $this->output()->writeln("An unrecoverable error has occurred:");
        $this->output()->writeln($exception->getMessage());
      }
    }
  }

  /**
   * Updates all product data.
   *
   * @command apw:run-product-renewal
   *
   * @throws \Exception
   */
  public function updateProductData() {
    $queue = $this->queue->get('amazon_product_widget.product_data_update');
    if ($this->productService->getProductStore()->hasStaleData()) {
      $this->productService->queueProductRenewal();

      /** @var \Drupal\amazon_product_widget\Plugin\QueueWorker\ProductDataUpdate $queueWorker */
      $queueWorker = $this->queueWorker->createInstance('amazon_product_widget.product_data_update');
      while ($item = $queue->claimItem()) {
        try {
          $queueWorker->processItem($item->data);
          $queue->deleteItem($item);
        }
        catch (SuspendQueueException $exception) {
          $queue->releaseItem($item);
          break;
        }
        catch (\Exception $exception) {
          watchdog_exception('amazon_product_widget', $exception);
        }
      }

      if ($this->productService->getProductStore()->hasStaleData()) {
        $outdated = $this->productService->getProductStore()->getOutdatedKeysCount();
        $this->output()->writeln("There are $outdated products still remaining.");
      }
      else {
        $this->output()->writeln("All items have been processed.");
      }
    }
    else {
      $this->output()->writeln("There is nothing to update.");
    }
  }

  /**
   * Gets the number of products due for renewal.
   *
   * @command apw:stale
   */
  public function itemsDueForRenewal() {
    $outdated = $this->productService->getProductStore()->getOutdatedKeysCount();
    $this->output()->writeln("There are $outdated products waiting for renewal.");
  }

  /**
   * Gets overrides for a specific Amazon product.
   *
   * @param string $asin
   *   The ASIN to get the overrides for.
   *
   * @command apw:overrides
   * @usage apw:overrides AE91ECBUDA
   */
  public function getOverridesForProduct($asin) {
    try {
      $productData = $this->productService->getProductData([$asin]);
      if (isset($productData[$asin]['overrides'])) {
        $this->output()->writeln("The following overrides were found for: $asin");
        $this->output()->writeln(var_export($productData[$asin]['overrides'], TRUE));
      }
      else {
        $this->output()->writeln("No product with ASIN $asin has been found.");
      }
    }
    catch (\Exception $exception) {
      $this->output()->writeln("An unexpected error has occurred:");
      $this->output()->writeln($exception->getMessage());
    }
  }

  /**
   * Resets all renewal times so all products are stale.
   *
   * @command apw:reset-all-renewals
   */
  public function resetAllRenewals() {
    $this->productService->getProductStore()->resetAll();
    $this->output()->writeln("All products have been marked for renewal.");
  }
}