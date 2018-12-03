<?php

namespace main\app\ctrl;

use main\app\classes\UserAuth;
use main\app\classes\UserLogic;
use main\app\model\system\AnnouncementModel;
use main\app\model\user\UserModel;
use main\app\protocol\Ajax;
use main\lib\MyPdo;

/**
 * 控制器基类
 *
 * @author user
 *
 */
class BaseCtrl
{
    /**
     * 模板引擎对象
     * @var
     */
    protected $tpl;

    /**
     * 模板引擎加载器
     * @var
     */
    protected $loader;

    /**
     * 错误数组
     * @var array
     */
    protected $error = [];

    /**
     * 全局模板变量数组
     * @var array
     */
    public $gTplVars = [];

    /**
     * 页面标题.
     * @var string
     */
    public $pageTitle = '';

    /**
     * 每个页面的token
     */
    public $csrfToken = '';

    /**
     * ajax请求失败时,客户端提示用户
     */
    const AJAX_FAILED_TYPE_TIP = 101;

    /**
     * ajax请求失败时,客户端显示警告信息
     */
    const AJAX_FAILED_TYPE_WARN = 102;

    /**
     * ajax请求失败时,客户端显示错误信息
     */
    const AJAX_FAILED_TYPE_ERROR = 103;

    /**
     * ajax请求失败时,告诉客户端将把错误信息显示在对应的表单项上
     */
    const AJAX_FAILED_TYPE_FORM_ERROR = 104;


    /**
     * BaseCtrl constructor.
     * @throws \Exception
     */
    public function __construct()
    {
        if (count($_GET) > 200) {
            throw new \Exception('GET参数过多', 300);
        }

        if (count($_POST) > 200) {
            throw new \Exception('POST参数过多', 400);
        }

        if (count($_COOKIE) > 50) {
            throw new \Exception('COOKIE参数过多', 500);
        }

        // 识别是否是page控制器 $this->pageInit();

        // 验证csrf_token
        if (isPost()) {
            if (!checkCsrfToken($_SERVER['HTTP_ML_CSRFTOKEN'], 'csrf_token')) {
                // 临时放行
                //throw new \Exception('_TOKEN 无效', 500);
            }
        }
    }

    /**
     * 每个页面控制器都应该在函数头部调用该方法
     */
    public function pageInit()
    {
        // 向每个页面输出csrf_token
        $this->csrfToken = csrfToken('csrf_token');
    }

    /**
     * 是否是ajax请求
     * @return bool
     */
    public function isAjax()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest';
    }

    public function addGVar($key, $value)
    {
        $this->gTplVars[$key] = $value;
    }

    /**
     * 控制器将数据传递给视图
     * @param $tpl
     * @param array $dataArr
     * @param bool $partial
     */
    public function render($tpl, $dataArr = [], $partial = false)
    {
        // 向视图传入通用的变量
        $this->addGVar('site_url', ROOT_URL);
        $this->addGVar('attachment_url', ATTACHMENT_URL);
        $this->addGVar('version', VERSION);
        $this->addGVar('app_name', SITE_NAME);
        $this->addGVar('csrf_token', $this->csrfToken);
        $user = [];
        $curUid = UserAuth::getInstance()->getId();
        if ($curUid) {
            $user = UserModel::getInstance($curUid)->getUser();
            $user = UserLogic::format($user);

        }
        $this->addGVar('user', $user);

        $dataArr = array_merge($this->gTplVars, $dataArr);
        ob_start();
        ob_implicit_flush(false);
        extract($dataArr, EXTR_PREFIX_SAME, 'tpl_');
        require_once VIEW_PATH . $tpl;
        if (!$partial && XPHP_DEBUG) {
            $sqlLogs = MyPdo::$sqlLogs;
            include_once VIEW_PATH . 'debug.php';
            unset($sqlLogs);
        }
        echo ob_get_clean();
    }


    /**
     * 重定向到一个新的url
     * @param  string $url
     */
    public function redirect($url)
    {
        $this->cleanOutput();
        header('Location:' . $url);
        exit;
    }

    public function cleanOutput()
    {
        for ($level = ob_get_level(); $level > 0; --$level) {
            if (!@ob_end_clean()) {
                ob_clean();
            }
        }
    }

    /**
     * 通过ajax 协议返回格式
     * @param string $msg
     * @param array $data
     * @param int $code
     * @throws \Exception
     */
    public function ajaxSuccess($msg = '', $data = [], $code = 200)
    {
        global $framework;
        $ajaxProtocolClass = sprintf("main\\%s\\protocol\\%s", $framework->currentApp, $framework->ajaxProtocolClass);
        if (class_exists($ajaxProtocolClass)) {
            //@var \main\app\protocol\Ajax
            $ajaxProtocol = new $ajaxProtocolClass();
        } else {
            $ajaxProtocol = new \framework\Protocol\Ajax();
        }
        $ajaxProtocol->builder($code, $data, $msg);
        $result = $ajaxProtocol->getResponse();

        if ($framework->enableReflectMethod) {
            $function = '';
            $traces = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            if (isset($traces[1]['class'])) {
                $function = $traces[1]['function'];
            }
            $reflectMethod = new \ReflectionMethod($this, $function);
            $returnObj = json_decode(json_encode($data));
            $this->validReturnJson($reflectMethod, $ajaxProtocol, $returnObj, $result);
        }

        @header('Content-Type:application/json');
        echo $result;
        exit;
    }

    /**
     * @param \ReflectionMethod $reflectMethod
     * @param \main\app\protocol\Ajax $ajaxProtocol
     * @param object $returnObj
     * @param string $jsonStr
     */
    private function validReturnJson($reflectMethod, $ajaxProtocol, $returnObj, &$jsonStr)
    {
        // 检查属性是否存在并且类型一致
        $commentString = $reflectMethod->getDocComment();
        if (!$commentString) {
            return;
        }
        $pattern = "#@require_type\s+([^*/].*)#";
        preg_match_all($pattern, $commentString, $matches, PREG_PATTERN_ORDER);
        if (isset($matches[1][0])) {
            $requireObj = json_decode($matches[1][0]);
            if ($requireObj !== null) {
                list($validRet, $validMsg) = $this->compareReturnJson($requireObj, $returnObj);
                if (!$validRet) {
                    $ajaxProtocol->builder('600', ['key' => 'return_type_err', 'value' => $validMsg]);
                    $jsonStr = $ajaxProtocol->getResponse();
                }
            }
        }
    }

    /**
     * 检查返回值是否符合格式要求
     *
     * @param object $requireTypeObj type object
     * @param object $returnObj return object
     *
     * @return array
     */
    private function compareReturnJson($requireTypeObj, $returnObj)
    {
        // 检查属性是否存在并且类型一致
        if (empty($requireTypeObj)
            && gettype($requireTypeObj) != gettype($returnObj)
        ) {
            return [false, 'expect  type is ' . gettype($returnObj) . ', but get ' . gettype($requireTypeObj)];
        }
        if (!empty($requireTypeObj) && is_object($requireTypeObj)) {
            foreach ($requireTypeObj as $k => $v) {
                if (!isset($returnObj->$k)) {
                    return [false, 'property:' . $k . ' not exist'];
                }
                if (gettype($v) != gettype($returnObj->$k)) {
                    return [false, 'expect ' . $k . ' type is ' . gettype($v) . ', but get ' . gettype($returnObj->$k)];
                }
                if (!empty($returnObj->$k) && (is_array($returnObj->$k) || is_object($returnObj->$k))) {
                    list($ret, $msg) = $this->compareReturnJson($v, $returnObj->$k);
                    if (!$ret) {
                        return [$ret, $msg];
                    }
                }
            }
        }
        return [true, ''];
    }

    /**
     * 通过ajax 协议返回异常格式
     * @param $msg
     * @param array $data
     * @param int $code
     */
    public function ajaxFailed($msg, $data = [], $code = 0)
    {
        header('Content-Type:application/json');
        $ajaxProtocol = new ajax();
        $ajaxProtocol->builder($code, $data, $msg);
        echo $ajaxProtocol->getResponse();
        exit;
    }

    public function jump($url, $info = null, $sec = 3)
    {
        if (is_null($info)) {
            header("Location:$url");
        } else {
            echo "<meta http-equiv=\"refresh\" content=" . $sec . ";URL=" . $url . ">";
            echo $info;
        }
        exit;
    }

    /**
     * 跳转至信息展示页面
     * @param string $title 标题
     * @param string $content 内容
     * @param array $links 链接
     * @param string $icon 图标样式
     */
    public function info(
        $title = '信息提示',
        $content = '',
        $links = ['type' => 'link', 'link' => '/', 'title' => '回到首页'],
        $icon = 'icon-font-ok'
    )
    {
        $arr = [];

        $arr['_title'] = $title;
        $arr['_links'] = $links;
        $arr['_content'] = $content;
        $arr['_icon'] = $icon;
        $this->render('gitlab/common/info.php', $arr);
    }

    /**
     * 跳转至警告页面
     * @param string $title 标题
     * @param string $content 内容
     * @param array $links 链接
     */
    public function warn(
        $title = '警告!',
        $content = '',
        $links = ['type' => 'link', 'link' => '/', 'title' => '回到首页']
    )
    {
        $this->info('<span style="color:orange">' . $title . '</span>', $content, $links, 'icon-font-fail');
    }

    /**
     * 跳转至错误页面
     * @param string $title 标题
     * @param string $content 内容
     * @param array $links 链接
     */
    public function error(
        $title = '错误提示!',
        $content = '',
        $links = ['type' => 'link', 'link' => '/', 'title' => '回到首页']
    )
    {
        $this->info('<span style="color:red">' . $title . '</span>', $content, $links, 'icon-font-fail');
    }


    /**
     * @return array
     * @throws \Exception
     */
    protected function getAnnouncement()
    {
        $model = new AnnouncementModel();
        $ret = $model->getRow('*', []);

        if (empty($ret)) {
            return [false];
        }

        if (!$ret['status']) {
            return [false];
        }

        if ($ret['expire_time'] < time()) {
            return [false];
        }

        if (isset($_COOKIE['announcement']) && $_COOKIE['announcement'] == $ret['flag']) {
            return [false];
        }

        return [true, $ret['content'], $ret['flag']];
    }
}
