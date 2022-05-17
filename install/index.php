<?

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\SystemException;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();
IncludeModuleLangFile(__FILE__);

class discount_comparison extends CModule
{
	var $MODULE_ID = 'r3ct4lan.discount_comparison';
	var $MODULE_NAME;
	var $MODULE_DESCRIPTION;
	var $MODULE_VERSION;
	var $MODULE_VERSION_DATE;
	var $PARTNER_NAME;
	var $PARTNER_URI;

	/**
	 * discount_comparison constructor.
	 */
	function __construct()
	{
		$this->MODULE_NAME = GetMessage("DISCOUNT_COMPARISON_MODULE_NAME");
		$this->MODULE_DESCRIPTION = GetMessage("DISCOUNT_COMPARISON_MODULE_DESCRIPTION");
		$this->PARTNER_NAME = GetMessage("DISCOUNT_COMPARISON_MODULE_PARTNER_NAME");
		$this->PARTNER_URI = GetMessage("DISCOUNT_COMPARISON_MODULE_PARTNER_URI");
		include(__DIR__ . '/version.php');
		if (isset($arModuleVersion)) {
			$this->MODULE_VERSION = $arModuleVersion["VERSION"];
			$this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
		}
	}

	/**
	 * Установка модуля
	 * @return bool
	 */
	function DoInstall()
	{
		global $APPLICATION;
		$result = true;
		try {
			ModuleManager::registerModule($this->MODULE_ID);
			if (Loader::includeModule($this->MODULE_ID)) {
				$this->InstallEvents();
			} else {
				throw new SystemException(GetMessage('DISCOUNT_COMPARISON_MODULE_NOT_REGISTERED'));
			}
		} catch (Exception $exception) {
			$result = false;
			$APPLICATION->ThrowException($exception->getMessage());
		}
		return $result;
	}

	/**
	 * Деинсталяция модуля
	 * @throws LoaderException
	 */
	function DoUninstall()
	{
		if (Loader::includeModule($this->MODULE_ID)) {
			$this->UninstallEvents();
			ModuleManager::unRegisterModule($this->MODULE_ID);
		}
	}


	/** Установка обработчиков событий */
	function InstallEvents()
	{
		RegisterModuleDependences(
			"main",
			"OnPageStart",
			$this->MODULE_ID,
			"\\R3ct4lan\\DiscountComparison\\Events\\Providers",
			"onPageStart",
			100
		);
	}

	/** Удаление обработчиков событий */
	function UninstallEvents()
	{
		UnRegisterModuleDependences(
			"main",
			"OnPageStart",
			$this->MODULE_ID,
			"\\R3ct4lan\\DiscountComparison\\Events\\Providers",
			"onPageStart",
			100
		);
	}

}


