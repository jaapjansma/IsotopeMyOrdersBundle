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

$GLOBALS['TL_DCA']['tl_module']['palettes']['jvh_orderhistory']             = '{title_legend},name,headline,type;{config_legend},iso_config_ids,jvh_document_id;{redirect_legend},jumpTo,iso_cart_jumpTo;{template_legend},customTpl,iso_includeMessages;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID,space';
$GLOBALS['TL_DCA']['tl_module']['palettes']['jvh_orderdetails']             = '{title_legend},name,headline,type;{config_legend},iso_loginRequired,jvh_document_id;iso_payment_modules;{redirect_legend:hide},iso_cart_jumpTo;{template_legend},customTpl,iso_collectionTpl,iso_orderCollectionBy,iso_gallery,iso_includeMessages;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID,space';

$GLOBALS['TL_DCA']['tl_module']['fields']['jvh_document_id'] = array
(
  'label'                     => &$GLOBALS['TL_LANG']['tl_module']['jvh_document_id'],
  'exclude'                   => true,
  'inputType'                 => 'select',
  'foreignKey'                => \Isotope\Model\Document::getTable().'.name',
  'eval'                      => array('includeBlankOption'=>true, 'mandatory'=>true, 'tl_class'=>'w50'),
  'sql'                       => "int(10) unsigned NOT NULL default '0'",
  'relation'                  => array('type'=>'hasOne', 'load'=>'lazy'),
);