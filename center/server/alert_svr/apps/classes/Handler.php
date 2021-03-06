<?php
namespace App;

/**
 * Class alert
 * @package App
 * 弹窗
 */
class Handler
{
    public $msg;
    public $alert;
    const SMS_INTERFACE_KEY = "platform/captcha_sms_interface";
    const SMS_CHANNEL_KEY = "platform/sms";
    const ENV = "product";
    static $sms_interface = array();
    static $sms_channel = array();

    function __construct($alert)
    {
        $this->msg = new \App\Msg($this);
        $this->alert = $alert;
        $this->worker_id = $this->alert->worker_id;
        $this->load_sms_config();
    }

    function load_sms_config()
    {
        //加载短信权重配置 短信验证码接口ID配置
        $sms_interface_path = "/data/config/".self::SMS_INTERFACE_KEY.".conf";
        $tmp_sms_interface = json_decode(file_get_contents($sms_interface_path),1);
        if (!empty($tmp_sms_interface)) {
            self::$sms_interface = $tmp_sms_interface;
        }

        $sms_channel_path =  "/data/config/".self::SMS_CHANNEL_KEY.".conf";
        $tmp_sms_channel = json_decode(file_get_contents($sms_channel_path),1);
        if ($tmp_sms_channel) {
            self::$sms_channel = $tmp_sms_channel;
        }
    }

    function repush_sms_config($interface)
    {
        $interface_id = $interface['interface_id'];
        if (empty($interface_id)) {
            return false;
        }

        $used = self::$sms_interface[$interface_id]['use'];
        $channel_id = self::$sms_interface[$interface_id]['id'];
        if (empty($used)) {
            return false;
        }

        $old_config = self::$sms_channel;
        $sms_channel = self::$sms_channel;
        if (empty($sms_channel)) {
            return false;
        }
        //该接口全部失败 将权重设置为0
        $need_push = false;
        foreach ($used as $use)
        {
            if (!empty($sms_channel['weight'][$use][$channel_id])) {
                $weight = $sms_channel['weight'][$use][$channel_id]['weight'];
                //无效的通道配置不重新推送
                if ($weight > 0) {
                    $sms_channel['weight'][$use][$channel_id]['weight'] = 0;
                    //遍历该功能下所有渠道，将失败的接口权重 转移至有效的渠道中
                    foreach ($sms_channel['weight'][$use] as $id => $channel) {
                        if ( ($id != $channel_id) and $channel['weight'] > 0) {
                            $sms_channel['weight'][$use][$id]['weight'] += $weight;
                            $need_push = true;
                            break;
                        }
                    }
                }
            }
        }
        if ($need_push) {
            //重新加载一次配置 给下一次请求使用
            $new_sms_config = json_encode($sms_channel);
            $curl = new \Swoole\Client\CURL();
            $url = "http://cc.oa.com/api/modify_config/";
            $data = array(
                'env' => self::ENV,
                'ckey' => self::SMS_CHANNEL_KEY,
                'config_data' => $new_sms_config,
            );
            $res = $curl->post($url,$data);
            unset($curl);
            \Swoole::$php->log->trace("repush sms old_config:".json_encode($old_config)." new_config:".$new_sms_config." push res: ".$res);
            $this->load_sms_config();
        }
        return false;
    }

    /**
     * @param $interface  接口信息
     * @param $data 当前时间统计数据
     */
    public function alert($interface,$data,$last_date)
    {
        //时间段没有数据上报  成功率不给报警
        if ($data['total_count'] > 0)
        {
            $succ_percent = number_format((($data['total_count']-$data['fail_count'])/$data['total_count'])*100,2);
            if ($succ_percent < $interface['succ_hold'])
            {
                //成功率低于配置
                $data['succ_percent'] = $succ_percent;
            }
            //短信验证码接口 成功率为0 修改短信配置
            //检查接口id
            if (($data['total_count']==$data['fail_count']) and in_array($interface['interface_id'],self::$sms_interface)) {
                $this->repush_sms_config($interface);
            }
        }

        //前一天表不存在不报警 今天的总量不够100 不触发报警
        //县官比
        if (false and ($data['total_count'] ==0) and is_array($last_date)) {
            //今天和昨天都没有数据 不报警
            //昨天没数据 今天有数据 报警
            //昨天有数据 今天没数据 报警
            //今天数据和昨天的波动值
            $last_success = $last_date['total_count']-$last_date['fail_count'];
            $wave = ($data['total_count']-$data['fail_count']) - $last_success;

            $wave_percent = 0;
            if($last_success!=0) {
                $wave_percent = number_format((abs($wave)/$last_success)*100,2);
            }

            if (isset($interface['wave_hold']) and  $wave_percent > $interface['wave_hold'])
            {
                if ($wave > 0)
                {
                    $data['flag'] = 1;//大于上次数据
                }
                else
                {
                    $data['flag'] = 2;//小于等于上次数据
                }
                //成功率低于配置
                $data['wave_percent'] = $wave_percent;
                $data['last_total_count'] = $last_date['total_count'];
                $data['last_fail_count'] = $last_date['fail_count'];

            }
        }
        //\Swoole::$php->redis->hSet(Alert::PREFIX."::".$interface['interface_id'],'last_succ_count_'.$time_key,$data['total_count']-$data['fail_count']);
        //$this->log("task worker {$this->worker_id}  data:".json_encode($data,JSON_UNESCAPED_UNICODE)." interface:".json_encode($interface,JSON_UNESCAPED_UNICODE));
        //成功率 或者 波动率 满足其中一个条件
        $msg = array_merge($interface,$data);
        if (isset($data['succ_percent']) or isset($data['wave_percent']))
        {
            if ($this->is_ready($msg))
            {
                \Swoole::$php->log->trace("meet alert condition,move to alert stage".json_encode($msg));
                $this->_alert($msg);
                \Swoole::$php->redis->hSet(ALert::PREFIX."::".$msg['interface_id'],'last_alert_time',time());
            }
        }
        else
        {
            \Swoole::$php->log->trace("alert condition do not meet,{$interface['interface_id']}return to next loop");
        }
    }

    private function is_ready($msg)
    {
        if (empty($msg['last_alert_time']))
        {
            \Swoole::$php->log->trace("task worker {$this->worker_id}  first time to msg {$msg['interface_id']}");
            return true;
        }
        else
        {
            $interval = $msg['alert_int'] * 60;//pop时间间隔 单位分钟
            if (time() - intval($msg['last_alert_time']) >= $interval) //间隔大于设置的间隔
            {
                \Swoole::$php->log->trace("task worker {$this->worker_id}  {$msg['interface_id']} time to msg; value:".
                    time()."-".intval($msg['last_alert_time'])."=".(time()-intval($msg['last_alert_time'])).", setting :".$interval);
                return true;
            }
            else
            {
                \Swoole::$php->log->trace("task worker {$this->worker_id}  {$msg['interface_id']} time is not ready to msg ;value:".
                    time()."-".intval($msg['last_alert_time'])."=".(time()-intval($msg['last_alert_time'])).", setting :".$interval);
                return false;
            }
        }
    }

    public function build_msg($message)
    {
        \Swoole\Filter::safe($message['module_name']);
        \Swoole\Filter::safe($message['interface_name']);
        $content = "紧急告警:".date("Y-m-d")." ".$this->get_time_string($message['time_key'])."-".$this->get_time_string($message['time_key']+1)
            ."  {$message['module_name']}->{$message['interface_name']} ";
        if (isset($message['succ_percent'])) //注意成功率为0 不要用empty 判断
        {
            $content .= "5分钟内调用{$message['total_count']}次，失败{$message['fail_count']}次，";
            $content .= "成功率{$message['succ_percent']}%低于{$message['succ_hold']}%，";
        }
        if (isset($message['wave_percent']) and !empty($message['wave_percent']))
        {
            $content .= "5分钟内调用{$message['total_count']}次，失败{$message['fail_count']}次，昨天同一时刻调用成功调用{$message['last_total_count']}次，失败{$message['last_fail_count']}次，";
            if ($message['flag'] == 1)
            {
                $content .= "波动率为增长{$message['wave_percent']}%，高于{$message['wave_hold']}%，";
            }
            if ($message['flag'] == 2)
            {
                $content .= "波动率为下降{$message['wave_percent']}%，高于{$message['wave_hold']}%，";
            }
        }
        if (isset($message['fail_server'])) {
            $fail_server = json_decode($message['fail_server'],1);
            if (!empty($fail_server)) {
                $content .= "服务器详情，";
                foreach ($fail_server as $ip => $count)
                {
                    $content .= "{$ip}失败{$count}次，";
                }
            }
        }
        if (isset($message['ret_code'])) {
            $ret_code = json_decode($message['ret_code'],1);
            if (!empty($ret_code)) {
                $content .= "错误码详情，";
                foreach ($ret_code as $o => $count)
                {
                    $code = $o > pow(2, 31) ? $o - pow(2, 32) : $o;
                    $content .= "{$code}:{$count}次，";
                }
            }
        }
        $content .= "请尽快处理。";
        return $content;
    }

    /**
     * @param $data 报警信息
     */
    private function _alert($msg)
    {
//        if (!empty($msg['alert_types']))
//        {
//            $alert_types = explode('|',$msg['alert_types']);
//            if (is_array($alert_types))
//            {
//                if (in_array(1,$alert_types))
//                {
//                    $this->alerts['pop']->alert($msg);
//                }
//                if (in_array(2,$alert_types))
//                {
//                    $this->alerts['msg']->alert($msg);
//                }
//            }
//        }
//        else
//        {
//            $this->log("alert types error".print_r($msg,1));
//        }
        return $this->msg->alert($msg);
    }

    public function log($msg)
    {
        $this->alert->log($msg);
    }

    public function get_time_string($time_key)
    {
        $h = floor($time_key / 12);
        $m = ($time_key % 12)*5;
        return $this->fill_zero_time($h).':'.$this->fill_zero_time($m);
    }

    public function fill_zero_time($s)
    {
        if (intval($s) < 10)
        {
            return '0'.$s;
        }
        else
        {
            return $s;
        }
    }
}