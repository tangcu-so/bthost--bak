<?php

namespace app\common\library;

use think\Config;
use think\Request;

class Site
{
    public function run()
    {
        // PHP版本检测
        if (PHP_VERSION < '7.2') {
            header('Content-Type:text/html; charset=utf-8');
            return sysmsg('您服务器PHP的版本太低，程序要求PHP版本不小于7.2');
        }
        if (!extension_loaded('curl')) {
            header('Content-Type:text/html; charset=utf-8');
            return sysmsg('当前站点不支持Curl模块，程序要求必须支持Curl模块');
        }

        // 线上需要开启站点域名效验
        // if($_SERVER['HTTP_HOST']!=$webInfo['webdomain']){
        //     exit('非法请求');
        // }

        //错误显示信息,非调试模式有效
        Config::set('error_message', '你所浏览的页面暂时无法访问');
        //跳转页面对应的模板文件
        Config::set('dispatch_success_tmpl', APP_PATH . DS . 'common' . DS . 'view' . DS . 'tpl' . DS . 'dispatch_jump.tpl');
        Config::set('dispatch_error_tmpl', APP_PATH . DS . 'common' . DS . 'view' . DS . 'tpl' . DS . 'dispatch_jump.tpl');
        Config::set('exception_tmpl', APP_PATH . DS . 'common' . DS . 'view' . DS . 'tpl' . DS . 'think_exception.tpl');

        // 获取网站配置
        if (!Config::get('site')) {
            return sysmsg('网站配置获取失败，请联系管理员');
        }

        //Debug模式
        if (Config::get('site.debug')) {
            config('app_debug', true);
            config('show_error_msg', true);
        }

        // 网站阻拦名单
        $ip = request()->ip();
        if (!$ip) {
            return sysmsg('IP异常，已被拦截');
        }
        $forbiddenip = Config::get('site.forbiddenip');
        if ($forbiddenip != '') {
            $black_arr = explode("\r\n", $forbiddenip);
            if (in_array($ip, $black_arr)) {
                return sysmsg('你被关小黑屋了！<br/>User_id:' . md5($ip));
            }
        }
    }
}
