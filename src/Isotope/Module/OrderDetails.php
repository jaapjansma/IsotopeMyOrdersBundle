<?php
/**
 * Copyright (C) 2023  Jaap Jansma (jaap.jansma@civicoop.org)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace JvH\IsotopeMyOrdersBundle\Isotope\Module;

use Contao\Controller;
use Contao\Database;
use Contao\Input;
use Haste\Util\Url;
use Isotope\Interfaces\IsotopeProductCollection;
use Isotope\Isotope;
use Isotope\Model\Document;
use Isotope\Model\Payment;
use Isotope\Model\ProductCollection\Order;
use Isotope\Module\OrderDetails as BaseOrderDetails;
use Isotope\Template;
use JvH\IsotopeCheckoutBundle\Validator;
use JvH\IsotopeMyOrdersBundle\Helper\PackagingSlip;
use Isotope\Model\OrderStatus;
use Isotope\Model\ProductCollectionLog;
use Haste\Util\Format;
use Krabo\IsotopePackagingSlipBundle\Helper\IsotopeHelper;
use Krabo\IsotopePackagingSlipBundle\Helper\PackagingSlipCheckAvailability;
use Krabo\IsotopePackagingSlipBundle\Model\IsotopePackagingSlipModel;
use Krabo\IsotopePackagingSlipBundle\Model\IsotopePackagingSlipShipperModel;

class OrderDetails extends BaseOrderDetails {

  protected $strFormId = 'jvh_mod_order_details';

  /**
   * Payment modules
   * @var array
   */
  private $modules;

  /**
   * Payment options
   * @var array
   */
  private $options;

  /**
   * @var string
   */
  protected $strCurrentStep;

  /**
   * Generate the module
   */
  protected function compile() {
    parent::compile();

    $this->strCurrentStep = \Haste\Input\Input::getAutoItem('step');
    if (empty($this->strCurrentStep)) {
      $this->strCurrentStep = Input::get('step');
    }

    $objOrder = $this->getCollection();
    if ($this->checkForDownloadInvoice($objOrder)) {
      return;
    }

    if ($this->strCurrentStep == 'complete' && $objOrder->isPaid()) {
      $packagingSlips = IsotopePackagingSlipModel::findPackagingSlipsByOrder($objOrder);
      $arrIds = [];
      foreach($packagingSlips as $packagingSlip) {
        if ($packagingSlip->status == 0) {
          $arrIds[] = $packagingSlip->id;
        }
      }
      if (count($arrIds)) {
        PackagingSlipCheckAvailability::resetAvailabilityStatus($arrIds);
      }

      $url = Url::removeQueryString(['step']);
      $url = str_replace("/complete.html?", ".html?", $url);
      Controller::redirect($url);
    }

    $this->Template->orderStatus          = $objOrder->getStatusLabel();
    $this->Template->trackAndTrace = PackagingSlip::getTrackAndTraceLinks($objOrder);
    $this->Template->packagingSlips = PackagingSlip::getPackagingSlipsByOrder($objOrder);
    $this->Template->invoice = Url::addQueryString('invoice=' . $objOrder->id);
    if ($this->ableToPay($objOrder)) {
      $this->Template->payUrl = Url::addQueryString('step=pay');
    }
    $this->Template->action        = ampersand(\Environment::get('request'));
    $this->Template->formId        = $this->strFormId;
    $this->Template->formSubmit    = $this->strFormId;
    $this->Template->enctype       = 'application/x-www-form-urlencoded';

    if ($this->checkChangeScheduledShippingDate($objOrder)) {
      return;
    }

    if (!$objOrder->isPaid()) {
      $this->checkForPayment($objOrder);
    }

    $this->addLogStatus($objOrder);
  }

  protected function ableToPay(Order $objOrder): bool {
    if (!$objOrder->isPaid()) {
      $this->initializePaymentModules();
      if (count($this->options)) {
        return true;
      }
    }
    return false;
  }

  protected function checkForPayment(Order $objOrder) {
    $this->initializePaymentModules();

    if ($this->strCurrentStep == 'complete') {
      $order = Order::findByPk($objOrder->id);
      $strBuffer = $order->hasPayment() ? $order->getPaymentMethod()->processPayment($order, $this) : true;
      if ($strBuffer === true) {
        // If checkout is successful, complete order and redirect to confirmation page
        if ($order->checkout() && $order->complete()) {
          Controller::redirect(Url::removeQueryString(['step']));
        }
      }
    } elseif ($this->strCurrentStep == 'pay') {

      $strClass = $GLOBALS['TL_FFL']['radio'];

      /** @var \Widget $objWidget */
      $objWidget = new $strClass([
        'id' => 'payment_method',
        'name' => 'payment_method',
        'mandatory' => TRUE,
        'options' => $this->options,
        'value' => $objOrder->payment_id,
        'storeValues' => TRUE,
        'tableless' => TRUE,
      ]);

      // If there is only one payment method, mark it as selected by default
      if (\count($this->modules) == 1) {
        $objModule = reset($this->modules);
        $objWidget->value = $objModule->id;
        $objOrder->setPaymentMethod($objModule);
      }

      if (Input::post('FORM_SUBMIT') == $this->strFormId) {
        $objWidget->validate();

        if (!$objWidget->hasErrors()) {
          $order = Order::findByPk($objOrder->id);
          $order->setPaymentMethod($this->modules[$objWidget->value]);
          $order->save();
          $strBuffer = $order->getPaymentMethod()->checkoutForm($order, $this);
          if ($strBuffer) {
            $this->Template->payment_method_checkoutform = $strBuffer;
          }
        }
      }

      /** @var Template|\stdClass $objTemplate */
      $objTemplate = new Template('iso_checkout_payment_method');
      $objTemplate->headline = $GLOBALS['TL_LANG']['MSC']['payment_method'];
      $objTemplate->options = $objWidget->parse();
      $objTemplate->paymentMethods = $this->modules;

      $this->Template->payment_method = $objTemplate->parse();
    }
  }

  protected function addLogStatus(Order $objOrder) {
    $logModels = ProductCollectionLog::findBy('pid', $objOrder->id, ['order' => 'tstamp']);
    $logTable = ProductCollectionLog::getTable();
    $logs = [];
    /** @var ProductCollectionLog $logModel */
    foreach ($logModels as $logModel) {
      $logData = $logModel->getData();

      /** @var OrderStatus $objNewStatus */
      $objStatus = OrderStatus::findByPk($logData['order_status']);
      $log = [
        'tstamp' => [
          'label' => Format::dcaLabel($logTable, 'tstamp'),
          'value' => $logModel->tstamp ? Format::dcaValue($logTable, 'tstamp', $logModel->tstamp) : '–'
        ],
        'status' => [
          'label' => 'Status',
          'value' => $objStatus->name
        ],
      ];

      $logs[$logModel->id] = $log;
    }
    $this->Template->logs = $logs;
  }

  protected function checkForDownloadInvoice(Order $objOrder): bool {
    $downloadInvoice = (int) \Input::get('invoice');
    if ($downloadInvoice === (int) $objOrder->id) {
      if (($objDocument = Document::findByPk($this->jvh_document_id)) !== null) {
        $objDocument->outputToBrowser($objOrder);
      }
    }
    return false;
  }

  protected function checkChangeScheduledShippingDate(Order $objOrder): bool {
    $objDatabase = Database::getInstance();
    $packagingSlipId = (int) \Input::get('change_packaging_slip_id');
    if ($packagingSlipId) {
      $packagingSlip = IsotopePackagingSlipModel::findByPk($packagingSlipId);
      if ($packagingSlip->isAllowedToChangeShippingDate()) {
        $earliestShippingDateStringValue = '';
        if ($packagingSlip->scheduled_shipping_date) {
          $earliestShippingDateStringValue = date('d-m-Y', $packagingSlip->scheduled_shipping_date);
        }
        $objShippingDateWidget = new $GLOBALS['TL_FFL']['text'](
          [
            'id' => 'shipping_date',
            'name' => 'shipping_date',
            'label' => $GLOBALS['TL_LANG']['MSC']['scheduled_shipping_date'][0],
            'description' => $GLOBALS['TL_LANG']['MSC']['scheduled_shipping_date'][1],
            'customTpl' => 'form_jvh_order_shippingdatefield',
            'value' => $earliestShippingDateStringValue,
          ]
        );
        if (\Input::post('FORM_SUBMIT') == $this->strFormId) {
          $reload = false;
          $earliestShippingDateTimeStamp = IsotopeHelper::getScheduledShippingDateForPackagingSlip($packagingSlip);
          $earliestShippingDate = date('d-m-Y', $earliestShippingDateTimeStamp);
          $objShippingDateWidget->validate();
          if (!$objShippingDateWidget->hasErrors()) {
            if (!empty($objShippingDateWidget->value)) {
              try {
                $scheduledShippingDate = new \DateTime($objShippingDateWidget->value);
                $scheduledShippingDate->setTime(23, 59);
                if (!Validator::isDate($objShippingDateWidget->value)) {
                  $objShippingDateWidget->addError(sprintf($GLOBALS['TL_LANG']['MSC']['scheduled_shipping_date_error'], $earliestShippingDate));
                }
                elseif ($scheduledShippingDate->getTimestamp() < $earliestShippingDateTimeStamp) {
                  $objShippingDateWidget->addError(sprintf($GLOBALS['TL_LANG']['MSC']['scheduled_shipping_date_error'], $earliestShippingDate));
                } else {
                  $objOrder->scheduled_shipping_date = $scheduledShippingDate->getTimestamp();
                  $packagingSlip->scheduled_shipping_date = $scheduledShippingDate->getTimestamp();
                  $packagingSlip->scheduled_picking_date = $this->getScheduledPickingDate($objOrder);
                  $packagingSlip->check_availability = '0';
                  $packagingSlip->is_available = '0'; // Uitgesteld
                  $packagingSlip->save();
                  $objDatabase->execute("UPDATE `tl_iso_product_collection` SET `scheduled_shipping_date` = '".$objOrder->scheduled_shipping_date."' WHERE `id` = '".$objOrder->id."'");
                  $reload = true;
                }
              } catch (\Exception $e) {
                $objShippingDateWidget->addError(sprintf($GLOBALS['TL_LANG']['MSC']['scheduled_shipping_date_error'], $earliestShippingDate));
              }
            }
            else {
              $objShippingDateWidget->addError(sprintf($GLOBALS['TL_LANG']['MSC']['scheduled_shipping_date_error'], $earliestShippingDate));
            }
          }

          if ($reload) {
            $url = Url::removeQueryString(['change_packaging_slip_id']);
            Controller::redirect($url);
          }
        }
        $this->Template->change_shipping_date = $objShippingDateWidget->parse();
      }
      return true;
    }
    return false;
  }

  private function initializePaymentModules()
  {
    if (null !== $this->modules && null !== $this->options) {
      return;
    }

    $this->modules = array();
    $this->options = array();

    $arrIds = deserialize($this->iso_payment_modules);

    if (!empty($arrIds) && \is_array($arrIds)) {
      $arrColumns = array('id IN (' . implode(',', $arrIds) . ')');

      if (BE_USER_LOGGED_IN !== true) {
        $arrColumns[] = "enabled='1'";
      }

      /** @var Payment[] $objModules */
      $objModules = Payment::findBy($arrColumns, null, array('order' => \Database::getInstance()->findInSet('id', $arrIds)));

      if (null !== $objModules) {
        foreach ($objModules as $objModule) {

          if (!$objModule->isAvailable()) {
            continue;
          }

          $strLabel = $objModule->getLabel();
          $fltPrice = $objModule->getPrice();

          if ($fltPrice != 0) {
            if ($objModule->isPercentage()) {
              $strLabel .= ' (' . $objModule->getPercentageLabel() . ')';
            }

            $strLabel .= ': ' . Isotope::formatPriceWithCurrency($fltPrice);
          }

          if ($note = $objModule->getNote()) {
            $strLabel .= '<span class="note">' . $note . '</span>';
          }

          $this->options[] = array(
            'value'     => $objModule->id,
            'label'     => $strLabel,
          );

          $this->modules[$objModule->id] = $objModule;
        }
      }
    }
  }

  public static function generateUrlForStep($strStep, IsotopeProductCollection $objCollection = null, \PageModel $objTarget = null)
  {
    if (null === $objTarget) {
      global $objPage;
      $objTarget = $objPage;
    }

    if (!$GLOBALS['TL_CONFIG']['useAutoItem'] || !\in_array('step', $GLOBALS['TL_AUTO_ITEM'], true)) {
      $strStep = 'step/' . $strStep;
    }

    $strUrl = Controller::generateFrontendUrl($objTarget->row(), '/' . $strStep, $objTarget->language);

    if (null !== $objCollection) {
      $strUrl = Url::addQueryString('uid=' . $objCollection->getUniqueId(), $strUrl);
    }

    return $strUrl;
  }

  protected function getScheduledPickingDate(Order $order) {
    $shipper = null;
    if ($order->getShippingMethod()->shipper_id) {
      $shipper = IsotopePackagingSlipShipperModel::findByPk($order->getShippingMethod()->shipper_id);
    }
    $earliestScheduledShippingDate = IsotopeHelper::getScheduledShippingDate($order, $shipper);
    $date = new \DateTime();
    $date->setTimestamp($earliestScheduledShippingDate);
    $date->setTime(23, 59);
    $earliestScheduledShippingDate = $date->getTimestamp();
    $earliestPickingDate = IsotopeHelper::getScheduledPickingDate($order, $shipper);
    if ($order->scheduled_shipping_date && $order->scheduled_shipping_date > $earliestScheduledShippingDate) {
      return $order->scheduled_shipping_date;
    }
    return $earliestPickingDate;
  }


}