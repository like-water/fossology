<?php
/*
 * Copyright (C) 2015-2017, Siemens AG
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */
namespace Fossology\SpdxTwoImport;

use Fossology\Lib\Data\License;
use EasyRdf_Graph;
require_once 'SpdxTwoImportData.php';
require_once 'SpdxTwoImportDataItem.php';

class SpdxTwoImportSource
{
  const TERMS = 'http://spdx.org/rdf/terms#';
  const SPDX_URL = 'http://spdx.org/licenses/';
  const SYNTAX_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

  /** @var EasyRdf_Graph */
  private $graph;
  /** @var array */
  private $index;
  /** @var string */
  private $licenseRefPrefix = "LicenseRef-";

  function __construct($filename, $uri = null)
  {
    $this->graph = $this->loadGraph($filename, $uri);
    $this->index = $this->loadIndex($this->graph);
    // $resources = $this->graph->resources(); // TODO it might be also worth to look at $graph->resources();
  }

  private function loadGraph($filename, $uri = null)
  {
    /** @var EasyRdf_Graph */
    $graph = new EasyRdf_Graph();
    $graph->parseFile($filename, 'rdfxml', $uri);
    return $graph;
  }

  private function loadIndex($graph)
  {
    return $graph->toRdfPhp();
  }

  public function getAllFileIds()
  {
    $fileIds = array();
    foreach ($this->index as $subject => $property){
      if ($this->isPropertyAFile($property))
      {
        $fileIds[] = $subject;
      }
    }
    return $fileIds;
  }

  private function isPropertyOfType(&$property, $type)
  {
    $key = self::SYNTAX_NS . 'type';
    $target = self::TERMS . $type;

    return is_array ($property) &&
      array_key_exists($key, $property) &&
      sizeof($property[$key]) === 1 &&
      $property[$key][0]['type'] === "uri" &&
      $property[$key][0]['value'] === $target;
  }

  private function isPropertyAFile(&$property)
  {
    return $this->isPropertyOfType($property, 'File');
  }

  public function getHashesMap($propertyId)
  {
    if ($this->isPropertyAFile($property))
    {
      return array();
    }

    $hashItems = $this->getValues($propertyId, 'checksum');

    $hashes = array();
    $keyAlgo = self::TERMS . 'algorithm';
    $algoKeyPrefix = self::TERMS . 'checksumAlgorithm_';
    $keyAlgoVal = self::TERMS . 'checksumValue';
    foreach ($hashItems as $hashItem)
    {
      $algorithm = $hashItem[$keyAlgo][0]['value'];
      if(substr($algorithm, 0, strlen($algoKeyPrefix)) === $algoKeyPrefix)
      {
        $algorithm = substr($algorithm, strlen($algoKeyPrefix));
      }
      $hashes[$algorithm] = $hashItem[$keyAlgoVal][0]['value'];
    }

    return $hashes;
  }

  private function getValue($propertyOrId, $key, $default=null)
  {
    $values = $this->getValues($propertyOrId, $key);
    if(sizeof($values) === 1)
    {
      return $values[0];
    }
    return $default;
  }

  private function getValues($propertyOrId, $key)
  {
    if (is_string($propertyOrId))
    {
      $property = $this->index[$propertyOrId];
    }
    else
    {
      $property = $propertyOrId;
    }

    $key = self::TERMS . $key;
    if (is_array($property) && isset($property[$key]))
    {
      $values = array();
      foreach($property[$key] as $entry)
      {
        if($entry['type'] === 'literal')
        {
          $values[] = $entry['value'];
        }
        elseif($entry['type'] === 'uri')
        {
          if(array_key_exists($entry['value'],$this->index))
          {
            $values[$entry['value']] = $this->index[$entry['value']];
          }
          else
          {
            $values[] = $entry['value'];
          }
        }
        elseif($entry['type'] === 'bnode')
        {
          $values[$entry['value']] = $this->index[$entry['value']];
        }
        else
        {
          echo "ERROR: can not handle entry=[".$entry."] of type=[" . $entry['type'] . "]\n"; // TODO
        }
      }
      return $values;
    }
    return array();
  }

  /**
   * @param $propertyId
   * @return array
   */
  public function getConcludedLicenseInfoForFile($propertyId)
  {
    return $this->getLicenseInfoForFile($propertyId, 'licenseConcluded');
  }

  /**
   * @param $propertyId
   * @return array
   */
  public function getLicenseInfoInFileForFile($propertyId)
  {
    return $this->getLicenseInfoForFile($propertyId, 'licenseInfoInFile');
  }

  private function stripLicenseRefPrefix($licenseId)
  {
    if(substr($licenseId, 0, strlen($this->licenseRefPrefix)) === $this->licenseRefPrefix)
    {
      return urldecode(substr($licenseId, strlen($this->licenseRefPrefix)));
    }
    else
    {
      return urldecode($licenseId);
    }
  }

  private function parseLicenseId($licenseId)
  {
    if (!is_string($licenseId))
    {
      echo "ERROR: Id not a string: ".$licenseId."\n";
      print_r($licenseId);
      return array();
    }
    if (strtolower($licenseId) === self::TERMS."noassertion" ||
        strtolower($licenseId) === "http://spdx.org/licenses/noassertion")
    {
      return array();
    }

    $license = $this->index[$licenseId];

    if ($license)
    {
      return $this->parseLicense($license);
    }
    elseif(substr($licenseId, 0, strlen(self::SPDX_URL)) === self::SPDX_URL)
    {
      $spdxId = urldecode(substr($licenseId, strlen(self::SPDX_URL)));
      $item = new SpdxTwoImportDataItem($spdxId);
      return array($item);
    }
    else
    {
      echo "ERROR: can not handle license with ID=".$licenseId."\n";
      return array();
    }
  }

  private function parseLicense($license)
  {
    if (is_string($license))
    {
      return $this->parseLicenseId($license);
    }
    elseif ($this->isPropertyOfType($license, 'ExtractedLicensingInfo'))
    {
      $licenseId = $this->stripLicenseRefPrefix($this->getValue($license,'licenseId'));

      if(strlen($licenseId) > 33 &&
         substr($licenseId, -33, 1) === "-" &&
         ctype_alnum(substr($licenseId, -32)))
      {
        $licenseId = substr($licenseId, 0, -33);
        $item = new SpdxTwoImportDataItem($licenseId);
        $item->setCustomText($this->getValue($license,'extractedText'));
        return array($item);

      }
      else
      {
        $item = new SpdxTwoImportDataItem($licenseId);
        $item->setLicenseCandidate($this->getValue($license,'name', $licenseId),
                                   $this->getValue($license,'extractedText'),
                                   strpos($this->getValue($license,'licenseId'), $this->licenseRefPrefix));
        return array($item);
      }
    }
    elseif ($this->isPropertyOfType($license, 'License'))
    {
      $licenseId = $this->stripLicenseRefPrefix($this->getValue($license,'licenseId'));
      $item = new SpdxTwoImportDataItem($licenseId);
      $item->setLicenseCandidate($this->getValue($license,'name', $licenseId),
                                 $this->getValue($license,'licenseText'),
                                 strpos($this->getValue($license,'licenseId'), $this->licenseRefPrefix));
      return array($item);
    }
    elseif ($this->isPropertyOfType($license, 'DisjunctiveLicenseSet') ||
            $this->isPropertyOfType($license, 'ConjunctiveLicenseSet')
    )
    {
      $output = array();
      $subLicenses = $this->getValues($license, 'member');
      if (sizeof($subLicenses) > 1 &&
          $this->isPropertyOfType($license, 'DisjunctiveLicenseSet'))
      {
        $output[] = new SpdxTwoImportDataItem("Dual-license");
      }
      foreach($subLicenses as $subLicense)
      {
        $innerOutput = $this->parseLicense($subLicense);
        foreach($innerOutput as $innerItem)
        {
          $output[] = $innerItem;
        }
      }
      return $output;
    }
    else
    {
      echo "ERROR: can not handle license=[".$license."] of type=[".gettype($license)."]\n"; // TODO
      return array();
    }
  }

  /**
   * @param $propertyId
   * @param $kind
   * @return array
   */
  private function getLicenseInfoForFile($propertyId, $kind)
  {
    $property = $this->index[$propertyId];
    $licenses = $this->getValues($property, $kind);

    $output = array();
    foreach ($licenses as $license)
    {
      $innerOutput = $this->parseLicense($license);
      foreach($innerOutput as $innerItem)
      {
        $output[] = $innerItem;
      }
    }
    return $output;
  }

  public function getCopyrightTextsForFile($propertyId)
  {
    return array_map('trim', $this->getValues($propertyId, "copyrightText"));
  }

  public function getDataForFile($propertyId)
  {
    return new SpdxTwoImportData($this->getLicenseInfoInFileForFile($propertyId),
                                 $this->getConcludedLicenseInfoForFile($propertyId),
                                 $this->getCopyrightTextsForFile($propertyId));
  }
}
