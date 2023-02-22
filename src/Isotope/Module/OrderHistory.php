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

use Haste\Generator\RowClass;
use Haste\Util\Format;
use Haste\Util\Url;
use Isotope\Isotope;
use Isotope\Message;
use Isotope\Model\Document;
use Isotope\Model\ProductCollection\Order;
use Isotope\Module\OrderHistory as BaseOrderHistory;
use Isotope\Template;
use JvH\IsotopeMyOrdersBundle\Helper\PackagingSlip;

class OrderHistory extends BaseOrderHistory {

  /**
   * Generate the module
   * @return void
   */
  protected function compile()
  {
    $arrOrders = [];
    $objOrders = Order::findBy(
      [
        'order_status>0',
        'tl_iso_product_collection.member=?',
        'config_id IN (' . implode(',', array_map('intval', $this->iso_config_ids)) . ')'
      ],
      [\FrontendUser::getInstance()->id],
      ['order' => 'locked DESC']
    );

    // No orders found, just display an "empty" message
    if (null === $objOrders) {
      $this->Template          = new Template('mod_message');
      $this->Template->type    = 'empty';
      $this->Template->message = $GLOBALS['TL_LANG']['ERR']['emptyOrderHistory'];

      return;
    }

    $reorder = (int) \Input::get('reorder');
    $downloadInvoice = (int) \Input::get('invoice');

    foreach ($objOrders as $objOrder) {
      if ($this->iso_cart_jumpTo && $reorder === (int) $objOrder->id) {
        $this->reorder($objOrder);
      } elseif ($downloadInvoice === (int) $objOrder->id) {
        if (($objDocument = Document::findByPk($this->jvh_document_id)) !== null) {
          $objDocument->outputToBrowser($objOrder);
        }
      }

      Isotope::setConfig($objOrder->getConfig());

      $arrOrders[] = [
        'collection' => $objOrder,
        'raw'        => $objOrder->row(),
        'date'       => Format::date($objOrder->locked),
        'time'       => Format::time($objOrder->locked),
        'datime'     => Format::datim($objOrder->locked),
        'grandTotal' => Isotope::formatPriceWithCurrency($objOrder->getTotal()),
        'status'     => $objOrder->getStatusLabel(),
        'link'       => $this->jumpTo ? (Url::addQueryString('uid=' . $objOrder->uniqid, $this->jumpTo)) : '',
        'reorder'    => $this->iso_cart_jumpTo ? (Url::addQueryString('reorder=' . $objOrder->id)) : '',
        'invoice'    => Url::addQueryString('invoice=' . $objOrder->id),
        'class'      => $objOrder->getStatusAlias(),
        'packagingSlipDocumentNumbers' => PackagingSlip::getPackagingSlipDocumentNumbers($objOrder),
        'trackAndTraceLinks' => PackagingSlip::getTrackAndTraceLinks($objOrder),
      ];
    }

    RowClass::withKey('class')->addFirstLast()->addEvenOdd()->applyTo($arrOrders);

    $this->Template->orders = $arrOrders;
  }

  private function reorder(Order $order)
  {
    Isotope::getCart()->copyItemsFrom($order);

    Message::addConfirmation($GLOBALS['TL_LANG']['MSC']['reorderConfirmation']);

    \Controller::redirect(
      Url::addQueryString(
        'continue=' . base64_encode(\System::getReferer()),
        $this->iso_cart_jumpTo
      )
    );
  }

}