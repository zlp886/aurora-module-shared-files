<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 * 
 */

namespace Aurora\Modules\SharedFiles\Storages\Sabredav;

use Afterlogic\DAV\FS\Shared\Root;
use Aurora\Api;
use Aurora\Modules\Core\Module;
use Aurora\System\Enums\FileStorageType;

use function Sabre\Uri\split;

/**
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.
 *
 * @internal
 * 
 * @package Filestorage
 * @subpackage Storages
 */
class Storage extends \Aurora\Modules\PersonalFiles\Storages\Sabredav\Storage
{

	/**
	 * @param string $sUserPublicId
	 * @param string $sType
	 * @param object $oItem
	 * @param string $sPublicHash
	 * @param string $sPath
	 *
	 * @return \Aurora\Modules\Files\Classes\FileItem|null
	 */
	public function getFileInfo($sUserPublicId, $sType, $oItem, $sPublicHash = null, $sPath = null)
	{
		$oResult = parent::getFileInfo($sUserPublicId, $sType, $oItem, $sPublicHash, $sPath);

		if (isset($oResult) /*&& ($oItem instanceof \Afterlogic\DAV\FS\Shared\File ||$oItem instanceof \Afterlogic\DAV\FS\Shared\Directory)*/)
		{
			$aExtendedProps = $oResult->ExtendedProps;
			$aExtendedProps['SharedWithMeAccess'] = (int) $oItem->getAccess();
			$oResult->ExtendedProps = $aExtendedProps;
		}

		return $oResult;
	}


	/**
	 * @param int $iUserId
	 * @param string $sType
	 * @param string $sPath
	 * @param string $sPattern
	 * @param string $sPublicHash
	 *
	 * @return array
	 */
	public function getFiles($sUserPublicId, $sType = \Aurora\System\Enums\FileStorageType::Personal, $sPath = '', $sPattern = '', $sPublicHash = null, $bIsShared = false)
	{
		$aResult = [];
		$oNode = false;
		
		$oServer = \Afterlogic\DAV\Server::getInstance();
		$oServer->setUser($sUserPublicId);

		if ($bIsShared)
		{
			list($share_path, $name) = split($sPath);
			if (!empty($share_path)) {
				$share_path = '/' . $share_path;
			}
			$oPdo = new \Afterlogic\DAV\FS\Backend\PDO();
			$aSharedFile = $oPdo->getSharedFileByUidWithPath('principals/' . $sUserPublicId, $name, $share_path);

			$oNode = Root::populateItem($aSharedFile);
			if ($oNode !== null && $oNode instanceof \Afterlogic\DAV\FS\Directory)
			{
				try
				{
					$aItems = $oNode->getChildren();
				}
				catch (\Exception $oEx)
				{
					\Aurora\Api::LogException($oEx);
				}
			}

			foreach ($aItems as $oItem)
			{
				$aResult[] = $this->getFileInfo($sUserPublicId, FileStorageType::Shared, $oItem, $sPublicHash, $sPath);
			}
		}
		else
		{
			if (empty($sPath)) {
				$sPath = 'files/' . FileStorageType::Shared;
			}
			else {
				$sPath = 'files/' . FileStorageType::Personal . '/'. trim($sPath, '/');
			}

			$oNode = $oServer->tree->getNodeForPath($sPath);
			if ($oNode instanceof \Sabre\DAV\ICollection) 
			{
				$depth = 1;
				if (!empty($sPattern))
				{
					$oServer->enablePropfindDepthInfinity = true;
					$depth = -1;
				}
	
				$oIterator = $oServer->getPropertiesIteratorForPath($sPath, [
					'{DAV:}displayname',
					'{DAV:}getlastmodified',
					'{DAV:}getcontentlength',
					'{DAV:}resourcetype',
					'{DAV:}quota-used-bytes',
					'{DAV:}quota-available-bytes',
					'{DAV:}getetag',
					'{DAV:}getcontenttype',
					'{DAV:}share-path',
				], $depth);
	
				foreach ($oIterator as $iKey => $oItem)
				{
					// Skipping the parent path
					if ($iKey === 0) continue;
	
					$sHref = $oItem['href'];
					list(, $sName) = \Sabre\Uri\split($sHref);
	
					if (empty($sPattern) || (fnmatch("*" . $sPattern . "*", $sName, FNM_CASEFOLD)))
					{
						$subNode = $oServer->tree->getNodeForPath($sHref);
						$sSharePath = isset($oItem[200]['{DAV:}share-path']) ? $oItem[200]['{DAV:}share-path'] : '';
						if (!empty($sSharePath)) {
							$sHref = str_replace($subNode->getName(), trim($sSharePath, '/') . '/' . $subNode->getName(), $sHref);
						}
	
						if ($subNode && !isset($aResult[$sHref]))
						{
							$aHref = \explode('/', $sHref, 3);
							list($sSubFullPath, ) = \Sabre\Uri\split($aHref[2]);
	
							$oFileInfo = parent::getFileInfo($sUserPublicId, $sType, $subNode, $sPublicHash, $sSubFullPath);
							// if ($oNode instanceof \Afterlogic\DAV\FS\Shared\Root && isset($oFileInfo) && ($subNode instanceof \Afterlogic\DAV\FS\Shared\File || $subNode instanceof \Afterlogic\DAV\FS\Shared\Directory))
							// {
							// 	if (!empty($sSharePath)) {
							// 		$oFileInfo->Name = trim($sSharePath, '/') . '/' . $subNode->getName();
							// 	}
							// }
	
							// if (isset($oFileInfo) /*&& ($oItem instanceof \Afterlogic\DAV\FS\Shared\File ||$oItem instanceof \Afterlogic\DAV\FS\Shared\Directory)*/)
							// {
							// 	$aExtendedProps = $oFileInfo->ExtendedProps;
							// 	$aExtendedProps['SharedWithMeAccess'] = (int) $oNode->getAccess();
							// 	$oFileInfo->ExtendedProps = $aExtendedProps;
							// }
	
	//						$oFileInfo->Name = \basename($subNode->getPath());
	
							$aResult[$sHref] = $oFileInfo;
						}
					}
				}
				$oServer->enablePropfindDepthInfinity = false;
				
				usort($aResult, 
					function ($a, $b) { 
						return ($a->Name > $b->Name); 
					}
				);			
			}
	
		}
		
		return $aResult;
	}
}

