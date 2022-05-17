<?

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!Loader::includeModule("sale")) {
	$APPLICATION->ThrowException(Loc::getMessage('ERROR_SALE_NOT_INSTALLED'));
	return false;
}

require_once __DIR__ . '/autoload.php';
