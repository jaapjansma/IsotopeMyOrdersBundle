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

namespace JvH\IsotopeMyOrdersBundle\Helper;

use Contao\Model\Collection;
use Isotope\Model\ProductCollection\Order;
use Krabo\IsotopePackagingSlipBundle\Model\IsotopePackagingSlipModel;

class PackagingSlip {

  protected static $packagingSlipsByOrderId = [];

  public static function getPackagingSlipsByOrder(Order $objOrder): array {
    if (!isset(static::$packagingSlipsByOrderId[$objOrder->id])) {
      static::$packagingSlipsByOrderId[$objOrder->id] = [];
      $packagingSlips = IsotopePackagingSlipModel::findPackagingSlipsByOrder($objOrder);
      if ($packagingSlips) {
        static::$packagingSlipsByOrderId[$objOrder->id] = $packagingSlips->getModels();
      }
    }
    return static::$packagingSlipsByOrderId[$objOrder->id];
  }

  public static function getPackagingSlipDocumentNumbers(Order $objOrder): array {
    $packagingSlipDocumentNumbers = [];
    /** @var IsotopePackagingSlipModel[] $packagingSlips */
    $packagingSlips = static::getPackagingSlipsByOrder($objOrder);
    foreach ($packagingSlips as $packagingSlip) {
      $packagingSlipDocumentNumbers[] = $packagingSlip->document_number;
    }
    return $packagingSlipDocumentNumbers;
  }

  public static function getTrackAndTraceLinks(Order $objOrder): array {
    $trackAndTraceLinks = [];
    /** @var IsotopePackagingSlipModel[] $packagingSlips */
    $packagingSlips = static::getPackagingSlipsByOrder($objOrder);
    foreach ($packagingSlips as $packagingSlip) {
      if ($packagingSlip->getTrackAndTraceLink()) {
        $trackAndTraceLinks[] = $packagingSlip->getTrackAndTraceLink();
      }
    }
    return $trackAndTraceLinks;
  }

}