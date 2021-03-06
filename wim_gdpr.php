<?php
/**
 * 2007-2018 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2018 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */


/**
 * IMPORTANTE!!
 *
 * CONFIGURACIÓN DEL MÓDULO:
 * Al instalar este módulo, hay que editar el template del CMS del tema en uso.
 * - El fichero en cuestión se encuentra en la ruta themes/[nombre-del-tema]/cms.tpl
 * - Justo encima del siguiente bloque de texto....
 * <div class="rte{if $content_only} content_only{/if}">
 *      {$cms->content}
 * </div>
 * - Se debe añadir la siguiente línea:
 * {hook h='displayCMSHistory'}
 * - Quedando algo parecido a:
 * {hook h='displayCMSHistory'}
 * <div class="rte{if $content_only} content_only{/if}">
 *      {$cms->content}
 * </div>
 *
 * La ejecución de este hook mostrará el histórico de cambios del CMS en cuestión.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Wim_gdpr extends Module
{
    protected $config_form = false;
    public $doubleHook = false; // En PS1.5 ejecutaba dos veces el hook "hookDisplayAdminForm" y se crea esta variable para controlarlo.

    public function __construct()
    {
        $this->name = 'wim_gdpr';
        $this->tab = 'others';
        $this->version = '1.0.0';
        $this->author = 'WebImpacto';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('WebImpacto GDPR');
        $this->description = $this->l('WebImpacto General Data Protection Regulation');

        $this->confirmUninstall = $this->l('Va a proceder a desistalar el módulo Wim_gdpr. ¿Esta seguro?');

        $this->ps_versions_compliancy = array('min' => '1.5', 'max' => _PS_VERSION_);
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        Configuration::updateValue('WIM_GDPR_LIVE_MODE', false);

        include(dirname(__FILE__) . '/sql/install.php');

        return parent::install() &&
        $this->registerHook('header') &&
        $this->registerHook('backOfficeHeader') &&
        $this->registerHook('displayHeader') &&
        $this->registerHook('displayAdminForm') &&
        $this->registerHook('displayCMSHistory') &&
        $this->registerHook('actionObjectCmsUpdateBefore');
    }

    public function uninstall()
    {
        Configuration::deleteByName('WIM_GDPR_LIVE_MODE');

        include(dirname(__FILE__) . '/sql/uninstall.php');

        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitWim_gdprModule')) == true) {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);
        $this->context->smarty->assign(array(
            'token' => Tools::getAdminTokenLite('AdminModules'),
        ));

        $this->context->smarty->assign('gdpr_list', $this->getGDPRList());
        if (version_compare(_PS_VERSION_, '1.7', '<') === true) {// Prestashop 1.6 / 1.5
            $this->context->smarty->assign('ps_version', "1.6");
        }else{// Prestashop 1.7
            $this->context->smarty->assign('ps_version', "1.7");
        }
        $output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        return $output;
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'WIM_GDPR_CMS_LIST' => Configuration::get('WIM_GDPR_CMS_LIST'),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();
        foreach (array_keys($form_values) as $key) {
            Configuration::updateGlobalValue($key, Tools::getValue($key));
        }
        $this->context->smarty->assign('gdpr_list', $this->getGDPRList());
    }

    /**
     * Add the CSS & JavaScript files you want to be loaded in the BO.
     */
    public function hookBackOfficeHeader()
    {
        // Si está en el listado de CMS:
        if (Tools::getValue('controller') == "AdminCmsContent" && !Tools::getValue('id_cms')) {
            $this->context->controller->addJquery();
            $this->context->controller->addJS($this->_path . 'views/js/cms_list.js');
        }
        // Si está editando un CMS:
        if (Tools::getValue('controller') == "AdminCmsContent" && Tools::getValue('id_cms')) {
            $this->context->controller->addJquery();

            $allShops = $this->getContextShop();

            if ($this->isCMSProtected(Tools::getValue('id_cms'), $allShops)) {
                $this->context->controller->addJS($this->_path . 'views/js/back.js');
            }
        }
        // Si está en la configuración del módulo:
        if (Tools::getValue('configure') == $this->name) {
            $this->context->smarty->assign('gdpr_list', $this->getGDPRList());
            $this->context->controller->addJS($this->_path . 'views/js/module_admin.js');
            $this->context->controller->addCSS($this->_path . 'views/css/back.css');
        }
    }

    public function getContextShop()
    {
        $shop = Shop::getContextShopID();
        $allShops = [];
        if ($shop == null) {// "Todas las tiendas"/"Default group" seleccionado
            foreach (Shop::getShops() as $shopElement) {
                $allShops[] = $shopElement["id_shop"];
            }
        } else {
            $allShops[] = $shop;
        }

        return $allShops;
    }

    /**
     * Comprueba si se está intentando desasociar alguna tienda de un CMS
     * @param $id_cms
     * @param $new_shop_list
     * @return bool
     */
    public function isDeletingShops($id_cms, $new_shop_list)
    {
        // Get current shops
        $current_shop_list = $this->getCmsShopList($id_cms);

        // Diff
        foreach ($current_shop_list as $current_shop) {
            foreach ($current_shop as $key => $current_shop_id) {
                if (!in_array($current_shop_id, $new_shop_list)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param $id_cms
     * @return array|false|mysqli_result|null|PDOStatement|resource
     * @throws PrestaShopDatabaseException
     * Devuelve un listado de tiendas asociado a un cms
     */
    public function getCmsShopList($id_cms)
    {
        $sql = 'SELECT id_shop
                FROM ' . _DB_PREFIX_ . 'cms_shop
                WHERE id_cms = ' . (int)$id_cms . ';';
        return Db::getInstance()->ExecuteS($sql);
    }


    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
        $this->context->controller->addJS($this->_path . '/views/js/front.js');
        $this->context->controller->addCSS($this->_path . '/views/css/front.css');
    }


    public function hookActionObjectCmsUpdateBefore()
    {
        $shop = Shop::getContextShopID();
        $shopList = [];
        if ($shop == null) {// "Todas las tiendas"/"Default group" seleccionado
            foreach (Shop::getShops() as $shopElement) {
                $shopList[] = $shopElement["id_shop"];
            }
        } else {
            $shopList[] = $shop;
        }

        $languageList = LanguageCore::getLanguages();

        // Tras la validación realizada por AJAX, aquí sólo nos queda comprobar si el CMS está protegido. De ser así, se guardará su versión en BBDD.
        if (count($languageList) > 0 && $this->isCMSProtected((int)Tools::getValue('id_cms'))) {
            foreach ($languageList as $language) {
                foreach ($shopList as $shop) {
                    $listaAuxiliarShop[0] = $shop;
                    if ($this->isCMSProtected((int)Tools::getValue('id_cms'), $listaAuxiliarShop)) {
                        $newCms = array(
                            'id_cms' => Tools::getValue('id_cms'),
                            'id_shop' => $shop,
                            'id_lang' => $language["id_lang"],
                            'id_employee' => Tools::getValue('id_employee'),
                            'new_meta_title' => Tools::getValue('meta_title_' . $language["id_lang"]),
                            'new_meta_description' => Tools::getValue('meta_description_' . $language["id_lang"]),
                            'new_meta_keywords' => Tools::getValue('meta_keywords_' . $language["id_lang"]),
                            'new_content' => Tools::getValue('content_' . $language["id_lang"]),
                            'new_link_rewrite' => Tools::getValue('link_rewrite_' . $language["id_lang"]),
                            'modification_reason_for_a_new' => Tools::getValue('modification_reason_for_a_new_' . $language["id_lang"]),
                            'show_to_users' => Tools::getValue('show_to_users'),
                        );
                        if (!$this->areCmsEquals(Tools::getValue('id_cms'), $language["id_lang"], null, $shop)) { // Cuando son identicos no se actualiza.
                            if (!$this->addWimGdprCmsVersions($newCms)) {
                                $this->errors[] = Tools::displayError($this->l('No se ha podido actualizar la tabla \' wim_gdpr_cms_versions\'.'));
                                return false;
                            }
                        } else {
                            $newShowToUsers = Tools::getValue('show_to_users');
                            $lastCmsVersion = $this->getLastCmsVersion(Tools::getValue('id_cms'), $language["id_lang"], $shop);
                            if ($newShowToUsers != $lastCmsVersion["show_to_users"]) {
                                $newCms['modification_reason_for_a_new'] = $this->l('Apartado \'Mostrar a usuarios\' modificado');
                                if (!$this->addWimGdprCmsVersions($newCms)) {
                                    $this->errors[] = Tools::displayError($this->l('No se ha podido actualizar la tabla \' wim_gdpr_cms_versions\'.'));
                                    return false;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function isCmsVersionDuplicated($obj)
    {
        $sql = 'SELECT *
                FROM ' . _DB_PREFIX_ . 'wim_gdpr_cms_versions
                WHERE id_cms = "' . pSQL($obj["id_cms"]) . '"
                AND id_shop = "' . pSQL($obj["id_shop"]) . '"
                AND id_lang = "' . pSQL($obj["id_lang"]) . '"
                AND id_employee = "' . pSQL($this->context->employee->id) . '"
                AND modification_reason_for_a_new = "' . pSQL($obj["modification_reason_for_a_new"]) . '"
                AND show_to_users = "' . pSQL($obj["show_to_users"]) . '"
                AND date_add = "' . date('Y-m-d H:i:s') . '"';

        return Db::getInstance()->getRow($sql);
    }

    /**
     * Muestra al usuario el popup para aceptar los cambios en los CMS si corresponde
     * @return mixed
     */
    public function hookDisplayHeader()
    {
        $cmsToAccept = $this->getCmsToShowToUser();
        if (count($cmsToAccept) > 0) {
            $this->context->smarty->assign('cmsList', $cmsToAccept);
            if (version_compare(_PS_VERSION_, '1.6', '<') === true) {// Prestashop 1.5
                $this->context->controller->addCSS($this->_path . '/views/css/tingle.min.css');
                $this->context->controller->addJS($this->_path . '/views/js/tingle.min.js');
                $this->context->controller->addJS($this->_path . '/views/js/1.5/front.js');
                return $this->display(__FILE__, 'views/templates/front/modal.tpl');
            } else { // Prestashop 1.6 en adelante
                $this->context->controller->addCSS($this->_path . '/views/css/modal.css');
                $this->context->controller->addJS($this->_path . '/views/js/front.js');
                return $this->display(__FILE__, 'modal.tpl');
            }
        }
    }

    public function getGDPRList()
    {
        $language_id = $this->context->language->id;
        $data = [];
        $sql = 'SELECT c.*, l.name AS languageName, s.name AS shopName
                FROM ' . _DB_PREFIX_ . 'lang l, ' . _DB_PREFIX_ . 'cms_lang c, ' . _DB_PREFIX_ . 'shop s
                WHERE c.id_lang = l.id_lang
                AND c.id_shop = s.id_shop
                AND c.id_lang = ' . $language_id . '
                ORDER BY id_shop ASC, id_cms ASC;';

        if (version_compare(_PS_VERSION_, '1.6', '<') === true) {// Prestashop 1.5
            $sql = 'SELECT c.*, l.name AS languageName, s.name AS shopName, s.id_shop
                    FROM ' . _DB_PREFIX_ . 'lang l, ' . _DB_PREFIX_ . 'cms_lang c, ' . _DB_PREFIX_ . 'shop s, ' . _DB_PREFIX_ . 'cms_shop cs
                    WHERE c.id_lang = l.id_lang
                    AND c.id_lang = ' . $language_id . '
                    AND cs.id_shop = s.id_shop
                    AND cs.id_cms = c.id_cms
                    ORDER BY S.id_shop ASC , id_cms ASC;';
        }

        $rows = Db::getInstance()->ExecuteS($sql);
        foreach ($rows as $row) {
            $data[$row["id_shop"]]["id"] = $row["id_shop"];
            $data[$row["id_shop"]]["name"] = $row["shopName"];
            $data[$row["id_shop"]]["cms"][$row["id_cms"]] = ["id" => $row["id_cms"], "meta_title" => $row["meta_title"], "checked" => "0"];
        }

        $configCmsList = json_decode(Configuration::get('WIM_GDPR_CMS_LIST'), true);

        foreach ($configCmsList as $row) {
            foreach ($row as $shop_id => $shop) {
                foreach ($shop as $cms) {
                    foreach ($cms as $cms_id) {
                        $data[$shop_id]["cms"][$cms_id]["checked"] = 1;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * @param $cms_id
     * @return bool
     * Comprueba si un cms esta controlado en el json de configuracion
     */
    public function isCMSProtected($cms_id = "", $allShops = null)
    {
        $json = json_decode(Configuration::get('WIM_GDPR_CMS_LIST'));

        if (empty($allShops)) {// Viene del listado
            foreach ($json->shop as $object) {
                foreach ($object as $cms_list) {
                    foreach ($cms_list as $cms) {
                        if ($cms_id == $cms) {
                            return true;
                        }
                    }
                }
            }
        } else { // Viene del CMS
            foreach ($allShops as $shop_id) {
                $cms_list = $json->shop->$shop_id;
                foreach ($cms_list as $cms_item) {
                    foreach ($cms_item as $cms) {
                        if ($cms_id == $cms) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }


    public function addWimGdprUserAceptance($id_gdpr_cms_version)
    {
        $id_customer = Wim_gdpr::getCurrentCustomer();

        $wim_gdpr_user_aceptance = array(
            'id_customer' => pSQL($id_customer),
            'id_gdpr_cms_version' => pSQL($id_gdpr_cms_version),
            'date_add' => date('Y-m-d H:i:s')
        );
        return (Db::getInstance()->insert('wim_gdpr_user_aceptance', $wim_gdpr_user_aceptance));
    }

    public function addWimGdprCmsVersions($newCms)
    {
        $id_cms = $newCms["id_cms"];
        $oldCms = $this->getCms($id_cms, $newCms["id_lang"]);

        if (version_compare(_PS_VERSION_, '1.6', '<') === true) { // Prestashop <= 1.5
            $newCms['old_meta_title'] = $oldCms["meta_title"];
            $newCms['old_meta_description'] = $oldCms["meta_description"];
            $newCms['old_meta_keywords'] = $oldCms["meta_keywords"];
            $newCms['old_content'] = $oldCms["content"];
            $newCms['old_link_rewrite'] = $oldCms["link_rewrite"];
            if ($this->isCmsVersionDuplicated($newCms)) { // Evitar insertar duplicados
                return true;
            }
        }

        $win_gdpr_cms_version = array('id_gdpr_cms_version' => 0,
            'id_cms' => pSQL($id_cms),
            'id_shop' => pSQL($newCms["id_shop"]),
            'id_lang' => pSQL($newCms["id_lang"]),
            'id_employee' => pSQL($this->context->employee->id),
            'old_meta_title' => pSQL($oldCms["meta_title"]),
            'old_meta_description' => pSQL($oldCms["meta_description"]),
            'old_meta_keywords' => pSQL($oldCms["meta_keywords"]),
            'old_content' => ($oldCms["content"]),
            'old_link_rewrite' => pSQL($oldCms["link_rewrite"]),
            'new_meta_title' => pSQL($newCms["new_meta_title"]),
            'new_meta_description' => pSQL($newCms["new_meta_description"]),
            'new_meta_keywords' => pSQL($newCms["new_meta_keywords"]),
            'new_content' => ($newCms["new_content"]),
            'new_link_rewrite' => pSQL($newCms["new_link_rewrite"]),
            'modification_reason_for_a_new' => pSQL($newCms["modification_reason_for_a_new"]),
            'show_to_users' => pSQL($newCms["show_to_users"]),
            'date_add' => date('Y-m-d H:i:s'),);

        return (Db::getInstance()->insert('wim_gdpr_cms_versions', $win_gdpr_cms_version));
    }

    /**
     * @param $id_cms
     * @param null $id_lang
     * @return array|bool|null|object
     * Obtiene un CMS
     */
    public function getCms($id_cms, $id_lang = null)
    {
        if (is_null($id_lang)) {
            $id_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        }
        $id_shop = Context::getContext()->shop->id;

        $sql = 'SELECT *
                FROM `' . _DB_PREFIX_ . 'cms_lang`
                WHERE `id_cms` = ' . (int)$id_cms . '
                AND `id_lang` = ' . (int)$id_lang . '
                AND `id_shop` = ' . (int)$id_shop;

        if (version_compare(_PS_VERSION_, '1.6', '<') === true) { // Prestashop <= 1.5
            $sql = 'SELECT *
                    FROM `' . _DB_PREFIX_ . 'cms_lang`
                    WHERE `id_cms` = ' . (int)$id_cms . '
                    AND `id_lang` = ' . (int)$id_lang;
        }
        return Db::getInstance()->getRow($sql);
    }

    /**
     * @return bool
     * Devuelve el id del cliente conectado.
     */
    public function getCurrentCustomer()
    {
        $id_customer = false;

        if (Context::getContext()->customer->id) {
            $id_customer = Context::getContext()->customer->id;
        }

        return $id_customer;
    }

    /**
     * Obtiene un listado de cms_lang para el lenguaje en el que esté abierta la web y para la tienda que esté abierta
     */
    public function getCmsList()
    {
        $id_lang = Context::getContext()->language->id;
        $id_shop = Context::getContext()->shop->id;

        $sql = 'SELECT *
                FROM ' . _DB_PREFIX_ . 'cms_lang
                WHERE id_lang = ' . (int)$id_lang . '
                AND id_shop = ' . (int)$id_shop . '
                ORDER BY id_cms ASC;';

        if (version_compare(_PS_VERSION_, '1.6', '<') === true) { // Prestashop <= 1.5
            $sql = 'SELECT c.*, s.id_shop
                FROM ' . _DB_PREFIX_ . 'cms_lang c, ' . _DB_PREFIX_ . 'cms_shop s
                WHERE c.id_cms = s.id_cms
                AND c.id_lang = ' . (int)$id_lang . '
                AND s.id_shop = ' . (int)$id_shop . '
                ORDER BY id_cms ASC;';
        }
        $rows = Db::getInstance()->ExecuteS($sql);

        return $rows;
    }

    /**
     * Obtiene un listado de los CMS protegidos por WIM_GDPR para el lenguaje en el que esté abierta la web y para la tienda que esté abierta
     */
    public function getProtectedCmsList()
    {
        $rows = $this->getCmsList();
        $list = array();
        foreach ($rows as $row) {
            if ($this->isCMSProtected($row["id_cms"])) {
                $list[] = $row["id_cms"];
            }
        }
        return $list;
    }

    /**
     * A partir de un listado de cms protegidos, se obtiene la última actualización en la tabla "wim_gdpr_cms_versions"
     * para cada cms y comprueba si se deben mostrar o no al usuario:
     * if "wim_gdpr_cms_versions.show_to_users" == 1 || "wim_gdpr_cms_versions.show_to_users" == 2
     */
    public function getCmsToShowToUser()
    {
        $data = array();
        $protectedCmsList = $this->getProtectedCmsList();
        $shop_id = (int)Context::getContext()->shop->id;

        $sql = 'SELECT v.*
                FROM ' . _DB_PREFIX_ . 'wim_gdpr_cms_versions v, ' . _DB_PREFIX_ . 'cms_shop s
                WHERE v.id_cms = s.id_cms
                AND s.id_shop = "' . $shop_id . '"
                AND v.id_gdpr_cms_version IN(
                    SELECT max(id_gdpr_cms_version)
                    FROM ' . _DB_PREFIX_ . 'wim_gdpr_cms_versions
                    WHERE id_cms IN (' . implode(",", $protectedCmsList) . ')
                    AND id_lang = ' . $this->context->language->id . '
                    AND id_shop = ' . $shop_id . '
                    GROUP BY id_cms
                )
                AND v.show_to_users IN (1)
                AND v.id_gdpr_cms_version NOT IN (
                    SELECT id_gdpr_cms_version
                    FROM ' . _DB_PREFIX_ . 'wim_gdpr_user_aceptance
                    WHERE id_customer = ' . $this->getCurrentCustomer() . '
                );';
        if ($rows = Db::getInstance()->ExecuteS($sql)) {
            foreach ($rows as $row) {
                $data[] = array(
                    "id_gdpr_cms_version" => $row["id_gdpr_cms_version"],
                    "id_cms" => $row["id_cms"],
                    "show_to_users" => $row["show_to_users"],
                    "content" => $row["new_content"],
                    "title" => $row["new_meta_title"],
                    "modification_reason_for_a_new" => $row["modification_reason_for_a_new"]
                );
            }
        }
        return $data;
    }

    /**
     * @param $id_cms
     * @return int
     * A partir de un "id_cms", devuelve el útlimo valor "show_to_users" asociado a este
     */
    public function getCmsShowToUserValue($id_cms, $id_shop)
    {
        $sql = 'SELECT show_to_users
                FROM ' . _DB_PREFIX_ . 'wim_gdpr_cms_versions
                WHERE id_cms = ' . $id_cms . '
                AND id_shop IN(' . implode(",", $id_shop) . ')
                ORDER BY id_gdpr_cms_version DESC';

        if ($row = Db::getInstance()->getRow($sql)) {
            return $row['show_to_users'];
        }
        return 0;
    }

    /**
     * @param $cmsList
     * @return bool
     * Al realizar un borrado multiple, comprueba uno por uno si el CMS está progedio
     */
    public function canDeleteMultipleCMS($cmsList)
    {
        foreach ($cmsList as $id_cms) {
            if (!Wim_gdpr::canDeleteCMS($id_cms)) {
                return false;
            }
        }

        return true;
    }

    public function canDeleteCMS($id_cms)
    {
        if (Wim_gdpr::isCMSProtected($id_cms)) {
            return false;
        }
        return true;
    }


    /**
     * @param $id_cms
     * @param $id_lang
     * @param $outputForm //data serialize form (validation)
     * @return bool
     * Esta funcion se debe llamar sólo al añadir/editar un CMS.
     * Comparará el CMS antes de editarse con el CMS que quedaría después de editarse y devolverá el resultado.
     */
    public function AreCmsEquals($id_cms, $id_lang, $outputForm = null, $id_shop = null)
    {
        if (isset($outputForm)) {
            $id_shop = $outputForm['current_id_shop'];
        }

        // Get old CMS
        if (version_compare(_PS_VERSION_, '1.6', '<') === true) { // Prestashop <= 1.5
            $sql = 'SELECT `meta_title`, `meta_description`, `meta_keywords`, `content`, `link_rewrite`
                FROM ' . _DB_PREFIX_ . 'cms_lang
                WHERE `id_cms` = ' . (int)$id_cms . '
                AND `id_lang` = ' . (int)$id_lang;
        } else { // Prestashop >= 1.6
            $sql = 'SELECT `meta_title`, `meta_description`, `meta_keywords`, `content`, `link_rewrite`
                FROM ' . _DB_PREFIX_ . 'cms_lang
                WHERE `id_cms` = ' . (int)$id_cms . '
                AND `id_lang` = ' . (int)$id_lang . '
                AND `id_shop` = ' . (int)$id_shop;
        }

        if ($old_cms = Db::getInstance()->getRow($sql)) {
            // Get new CMS
            if (!empty($outputForm)) {
                $new_cms = array(
                    'meta_title' => $outputForm['meta_title_' . $id_lang],
                    'meta_description' => $outputForm['meta_description_' . $id_lang],
                    'meta_keywords' => $outputForm['meta_keywords_' . $id_lang],
                    'content' => $outputForm['content_' . $id_lang],
                    'link_rewrite' => $outputForm['link_rewrite_' . $id_lang],
                );
            } else {
                $new_cms = array(
                    'meta_title' => Tools::getValue('meta_title_' . $id_lang),
                    'meta_description' => Tools::getValue('meta_description_' . $id_lang),
                    'meta_keywords' => Tools::getValue('meta_keywords_' . $id_lang),
                    'content' => Tools::getValue('content_' . $id_lang),
                    'link_rewrite' => Tools::getValue('link_rewrite_' . $id_lang),
                );
            }
            $old_cms['content'] = strtolower(preg_replace('/[^a-zA-Z0-9-_\.]/', '', preg_replace("/(\r\n)+|\r+|\n+|\t+/i", " ", $old_cms['content'])));
            $new_cms['content'] = strtolower(preg_replace('/[^a-zA-Z0-9-_\.]/', '', preg_replace("/(\r\n)+|\r+|\n+|\t+/i", " ", $new_cms['content'])));

            // Compare
            if ($old_cms['content'] == $new_cms['content']) {// No se realiza comparación binaria por si los tipos de datos fueran distintos
                return true;
            } else {
                return false;
            }
        } else {// Se está creando uno nuevo
            return false;
        }
    }

    /**
     * Devuelve un registro de la tabla 'wim_gdpr_cms_versions' a partir de un id recibido
     * @param $id_gdpr_cms_version
     * @return array|bool|null|object
     */
    public function getCmsVersion($id_gdpr_cms_version)
    {
        $sql = '
			SELECT *
			FROM `' . _DB_PREFIX_ . 'wim_gdpr_cms_versions`
			WHERE `id_gdpr_cms_version` = ' . (int)$id_gdpr_cms_version;

        return Db::getInstance()->getRow($sql);
    }

    public function getLastCmsVersion($id_cms, $id_lang, $id_shop)
    {
        $sql = '
			SELECT *
			FROM `' . _DB_PREFIX_ . 'wim_gdpr_cms_versions`
			WHERE `id_cms` = ' . (int)$id_cms . '
			AND `id_shop` = ' . (int)$id_shop . '
			AND `id_lang` = ' . (int)$id_lang . '
			ORDER BY id_gdpr_cms_version DESC
			';

        return Db::getInstance()->getRow($sql);
    }

    public function hookDisplayAdminForm($params)
    {
        if (isset($this->doubleHook) && $this->doubleHook) {
            return "";
        }
        $this->doubleHook = true;

        $selectedShopList = $this->getContextShop();
        if ($this->isCMSProtected(AdminCmsControllerCore::getFieldValue($this->object, 'id_cms'), $selectedShopList)) {
            $languageList = LanguageCore::getLanguages();
            $this->smarty->assign('languageList', $languageList);
            $this->smarty->assign('show_to_users', $this->getCmsShowToUserValue(AdminCmsControllerCore::getFieldValue($this->object, 'id_cms'), $this->getContextShop()));
            $this->smarty->assign('url', __PS_BASE_URI__);
            $this->smarty->assign('current_id_shop', $this->context->shop->id);

            if (version_compare(_PS_VERSION_, '1.6', '<') === true) {// Prestashop <= 1.5
                return $this->display(__FILE__, 'views/templates/admin/cms_fields_1.5.tpl');
            } else { // Prestashop >= 1.6
                return $this->display(__FILE__, 'views/templates/admin/cms_fields.tpl');
            }
        }
    }


    /**
     * Si existen errores en las validaciones establecidas devuelve mensaje de error correspondiente
     * @param $data //formulario serializado
     * @return array|bool|null|object
     */
    public function  validationForm($data)
    {
        $errors = array();
        parse_str($data, $outputForm);
        $cmsShops = $this->getCMSshop($outputForm['id_cms']);
        $langs = LanguageCore::getLanguages();
        // Comprobar que se ha insertado un motivo de modificacion
        foreach ($langs as $input) {
            if (!$this->AreCmsEquals($outputForm['id_cms'], $input['id_lang'], $outputForm)) {
                if ($outputForm['modification_reason_for_a_new_' . $input['id_lang']] == "") {
                    $errors [] = Tools::displayError($this->l('Debe indicar el motivo de la modificación de este CMS para el idioma ' . $input['name'] . '.'));
                }
            }
        }

        // Comprobar que no se desactive un CMS protegido
        if ($outputForm['active'] == 0) {
            $errors [] = Tools::displayError($this->l('El CMS que intenta desactivar se encuentra protegido. Para poder desactivarlo tiene que anular dicha protección en el módulo correspondiente (WebImpacto GDPR)'));
        }

        if (count($cmsShops) > 1) { // Si es multitienda...
            foreach ($cmsShops as $key => $shop) {
                if (!in_array($shop['id_shop'], $outputForm['itemShopSelected'])) {
                    $errors [] = Tools::displayError($this->l('No pude desmarcar una tienda que ha sido marcada con contenido protegido (Tienda : ' . $shop['name'] . ')'));
                }
            }
        }
        return $errors;
    }

    /**
     * Devuelve un historico de versiones de un CMS
     * @param $cms_id
     * @throws PrestaShopDatabaseException
     */
    public function getCmsVersionHistory($cms_id)
    {
        $lang_id = $this->context->language->id;
        $shop_id = Context::getContext()->shop->id;

        $sql = 'SELECT *
        FROM `' . _DB_PREFIX_ . 'wim_gdpr_cms_versions`
        WHERE `show_to_users` in(1,2)
        AND `id_cms` = ' . (int)$cms_id . '
        AND `id_shop` = ' . (int)$shop_id . '
        AND `id_lang` = ' . (int)$lang_id . '
        ORDER BY `date_add`, `id_gdpr_cms_version`;';

        return Db::getInstance()->ExecuteS($sql);
    }

    public function  getCMSshop($id_cms)
    {
        $sql = '
			SELECT *
			FROM `' . _DB_PREFIX_ . 'cms_shop` cs
            LEFT JOIN `' . _DB_PREFIX_ . 'shop` s ON cs.`id_shop` = s.`id_shop`
            WHERE cs.`id_cms` = ' . (int)$id_cms;

        return Db::getInstance()->ExecuteS($sql);
    }

    public function hookDisplayCMSHistory($params)
    {
        $id_cms = Tools::getValue('id_cms');
        $selectedShop = $this->getContextShop();
        if ($this->isCMSProtected($id_cms, $selectedShop)) {
            $this->context->smarty->assign('cmsVersionHistory', $this->getCmsVersionHistory($id_cms));
            return $this->context->smarty->fetch($this->local_path . 'views/templates/front/cms_history.tpl');
        }
    }
}