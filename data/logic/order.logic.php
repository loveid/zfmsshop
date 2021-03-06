<?php
/**
 * 实物订单行为
 *
 * @copyright  Copyright (c) 2007-2018 ShopNC Inc. (http://www.shopnc.net)
 * @license    http://www.shopnc.net
 * @link       http://www.shopnc.net
 * @since      File available since Release v1.1
 */
defined('InShopNC') or exit('Access Invalid!');
class orderLogic {

    /**
     * 取消订单
     * @param array $order_info
     * @param string $role 操作角色 buyer、seller、admin、system 分别代表买家、商家、管理员、系统
     * @param string $user 操作人
     * @param string $msg 操作备注
     * @param boolean $if_update_account 是否变更账户金额
     * @param array $cancel_condition 订单更新条件,目前只传入订单状态，防止并发下状态已经改变
     * @return array
     */
    public function changeOrderStateCancel($order_info, $role, $user = '', $msg = '', $if_update_account = true, $cancel_condition = array()) {
        try {
            $model_order = Model('order');
            $model_order->beginTransaction();
            $order_id = $order_info['order_id'];
            $_info = $model_order->table('orders')->where(array('order_id'=> $order_id))->master(true)->lock(true)->find();
            if ($_info['order_state'] == ORDER_STATE_CANCEL) {
                throw new Exception('参数错误');
            }

            //库存销量变更
            $goods_list = $model_order->getOrderGoodsList(array('order_id'=>$order_id));
            $data = array();
            $goods_sale = array();
            foreach ($goods_list as $goods) {
                $data[$goods['goods_id']] = $goods['goods_num'];
                $goods_sale[$goods['goods_id']] = $goods['goods_commonid'];
            }
            $result = Logic('queue')->cancelOrderUpdateStorage($data, $goods_sale);
            if (!$result['state']) {
                throw new Exception('还原库存失败');
            }
            if ($order_info['order_type'] == 3 || $order_info['order_type'] == 5) {
                $result = Logic('queue')->cancelOrderUpdateChainStorage($data,$order_info['chain_id']);
                if (!$result['state']) {
                    throw new Exception('还原门店库存失败');
                }
            }

            if ($if_update_account) {
                $model_pd = Model('predeposit');
                //解冻充值卡
                $rcb_amount = floatval($order_info['rcb_amount']);
                if ($rcb_amount > 0) {
                    $data_pd = array();
                    $data_pd['member_id'] = $order_info['buyer_id'];
                    $data_pd['member_name'] = $order_info['buyer_name'];
                    $data_pd['amount'] = $rcb_amount;
                    $data_pd['order_sn'] = $order_info['order_sn'];
                    $model_pd->changeRcb('order_cancel',$data_pd);
                }

                //解冻预存款
                $pd_amount = floatval($order_info['pd_amount']);
                if ($pd_amount > 0) {
                    $data_pd = array();
                    $data_pd['member_id'] = $order_info['buyer_id'];
                    $data_pd['member_name'] = $order_info['buyer_name'];
                    $data_pd['amount'] = $pd_amount;
                    $data_pd['order_sn'] = $order_info['order_sn'];
                    $model_pd->changePd('order_cancel',$data_pd);
                }
            }

            //更新订单信息
            $update_order = array('order_state'=>ORDER_STATE_CANCEL);
            $cancel_condition['order_id'] = $order_id;
            $cancel_condition['order_state'] = array('neq', ORDER_STATE_CANCEL);
            $update = $model_order->editOrder($update_order,$cancel_condition);
            if (!$update) {
                throw new Exception('保存失败');
            }

            //添加订单日志
            $data = array();
            $data['order_id'] = $order_id;
            $data['log_role'] = $role;
            $data['log_msg'] = '取消了订单';
            $data['log_user'] = $user;
            if ($msg) {
                $data['log_msg'] .= ' ( '.$msg.' )';
            }
            $data['log_orderstate'] = ORDER_STATE_CANCEL;
            $model_order->addOrderLog($data);
            if ($order_info['order_type'] == 3 || $order_info['order_type'] == 5) {
                Model('chain_voucher')->returnVoucher($order_info['order_id']);
            } else {
                Model('voucher')->returnVoucher($order_info['order_id']);
            }
            $model_order->commit();

            return callback(true,'操作成功');

        } catch (Exception $e) {
            $model_order->rollback();
            return callback(false,'操作失败');
        }
    }

    /**
     * 收货
     * @param array $order_info
     * @param string $role 操作角色 buyer、seller、admin、system,chain 分别代表买家、商家、管理员、系统、门店
     * @param string $user 操作人
     * @param string $msg 操作备注
     * @return array
     */
    public function changeOrderStateReceive($order_info, $role, $user = '', $msg = '') {
        try {

            $order_id = $order_info['order_id'];
            $model_order = Model('order');

            //更新订单状态
            $update_order = array();
            $update_order['finnshed_time'] = TIMESTAMP;
            $update_order['order_state'] = ORDER_STATE_SUCCESS;
            $update = $model_order->editOrder($update_order,array('order_id'=>$order_id));
            if (!$update) {
                throw new Exception('保存失败');
            }

            //添加订单日志
            $data = array();
            $data['order_id'] = $order_id;
            $data['log_role'] = $role;
            $data['log_msg'] = $msg;
            $data['log_user'] = $user;
            $data['log_orderstate'] = ORDER_STATE_SUCCESS;
            $model_order->addOrderLog($data);

            if ($order_info['buyer_id'] > 0 && $order_info['order_amount'] > 0) {
                Model('store')->editStore(array('store_sales'=>array('exp','store_sales+1')),array('store_id'=>$order_info['store_id']));//更新店铺销量
                //添加会员积分
                if (C('points_isuse') == 1){
                    Model('points')->savePointsLog('order',array('pl_memberid'=>$order_info['buyer_id'],'pl_membername'=>$order_info['buyer_name'],'orderprice'=>$order_info['order_amount'],'order_sn'=>$order_info['order_sn'],'order_id'=>$order_info['order_id']),true);
                }
                //添加会员经验值
                Model('exppoints')->saveExppointsLog('order',array('exp_memberid'=>$order_info['buyer_id'],'exp_membername'=>$order_info['buyer_name'],'orderprice'=>$order_info['order_amount'],'order_sn'=>$order_info['order_sn'],'order_id'=>$order_info['order_id']),true);
            }
            $refund_array = array();
            $refund_array['finnshed_time'] = time();
            Model('refund_return')->editRefundReturn(array('order_id'=> $order_id), $refund_array);//更新退款完成时间（结算使用）

            return callback(true,'操作成功');
        } catch (Exception $e) {
            return callback(false,'操作失败');
        }
    }

    /**
     * 更改订单金额
     * @param array $order_info
     * @param string $role 操作角色 buyer、seller、admin、system 分别代表买家、商家、管理员、系统
     * @param string $user 操作人
     * @return array
     */
    public function changeOrderPrice($order_info, $role, $user = '', $post) {
        try {

            $order_id = $order_info['order_id'];
            $model_order = Model('order');

            $info = array();
            $info['shipping_fee'] = ncPriceFormat(abs(floatval($post['shipping_fee'])));
            $goods_amount = 0;
            foreach ($order_info['extend_order_goods'] as $k => $v) {
                $goods_id = $v['rec_id'];//订单商品表编号
                $goods_amount = ncPriceFormat($goods_amount+$v['goods_pay_price']);
                $_amount = floatval($post['goods_'.$goods_id]);//优惠金额
                if ($_amount > 0 && $_amount < $v['goods_pay_price']) {
                    $goods_amount = ncPriceFormat($goods_amount-$_amount);
                    $model_order->editOrderGoods(array('goods_pay_price' => ncPriceFormat($v['goods_pay_price']-$_amount)),array('rec_id' => $v['rec_id']));
                }
            }
            $info['goods_amount'] = ncPriceFormat($goods_amount);
            if ($info['goods_amount'] < 0.01) {
                throw new Exception('订单商品金额错误');
            }
            $info['order_amount'] = ncPriceFormat($info['goods_amount']+$info['shipping_fee']);
            $update = $model_order->editOrder($info,array('order_id'=>$order_id));
            if (!$update) {
                throw new Exception('订单保存失败');
            }
            //记录订单日志
            $data = array();
            $data['order_id'] = $order_id;
            $data['log_role'] = $role;
            $data['log_user'] = $user;
            $data['log_msg'] = '修改了运费( '.$info['shipping_fee'].' )，商品金额( '.$info['goods_amount'].' )';
            $data['log_orderstate'] = $order_info['payment_code'] == 'offline' ? ORDER_STATE_PAY : ORDER_STATE_NEW;
            $model_order->addOrderLog($data);
            return callback(true,'操作成功');
        } catch (Exception $e) {
            return callback(false,'操作失败');
        }
    }

    /**
     * 商家主动取消，订单全部退款
     * @return array
     */
    public function sellerOrderRefund($order_info, $post) {
        if ($order_info['payment_time']) {
            $model_refund = Model('refund_return');
            $order_id = $order_info['order_id'];
            $refund_array = array();
            $refund_array['refund_type'] = '1';//类型:1为退款,2为退货
            $refund_array['seller_state'] = '2';//状态:1为待审核,2为同意,3为不同意
            $refund_array['order_lock'] = '2';//锁定类型:1为不用锁定,2为需要锁定
            $refund_array['refund_state'] = '2';//状态:1为处理中,2为待管理员处理,3为已完成
            $refund_array['goods_id'] = '0';
            $refund_array['order_goods_id'] = '0';
            $refund_array['reason_id'] = '0';
            $refund_array['reason_info'] = '取消订单，全部退款';
            $refund_array['goods_name'] = '订单商品全部退款';
            $refund_array['refund_amount'] = ncPriceFormat($order_info['order_amount']);
            $refund_array['buyer_message'] = '商家主动取消,待管理员确认退款';
            $refund_array['add_time'] = TIMESTAMP;
            $refund_array['seller_time'] = TIMESTAMP;
            $msg = $post['state_info1'] != '' ? $post['state_info1'] : $post['state_info'];
            $refund_array['seller_message'] = $msg;
            $state = $model_refund->addRefundReturn($refund_array,$order_info);
            if ($state) {
                $model_refund->editOrderLock($order_id);
                return callback(true,'操作成功');
            }
        }
        return callback(false,'操作失败');
    }

    /**
     * 回收站操作（放入回收站、还原、永久删除）
     * @param array $order_info
     * @param string $role 操作角色 buyer、seller、admin、system 分别代表买家、商家、管理员、系统
     * @param string $state_type 操作类型
     * @return array
     */
    public function changeOrderStateRecycle($order_info, $role, $state_type) {
        $order_id = $order_info['order_id'];
        $model_order = Model('order');
        //更新订单删除状态
        $state = str_replace(array('delete','drop','restore'), array(ORDER_DEL_STATE_DELETE,ORDER_DEL_STATE_DROP,ORDER_DEL_STATE_DEFAULT), $state_type);
        $update = $model_order->editOrder(array('delete_state'=>$state),array('order_id'=>$order_id));
        if (!$update) {
            return callback(false,'操作失败');
        } else {
            return callback(true,'操作成功');
        }
    }

    /**
     * 发货
     * @param array $order_info
     * @param string $role 操作角色 buyer、seller、admin、system 分别代表买家、商家、管理员、系统
     * @param string $user 操作人
     * @return array
     */
    public function changeOrderSend($order_info, $role, $user = '', $post = array()) {
        $order_id = $order_info['order_id'];
        $model_order = Model('order');
        try {
            $model_order->beginTransaction();
            $data = array();
            if (!empty($post['reciver_name'])) {
                $data['reciver_name'] = $post['reciver_name'];
            }
            if (!empty($post['reciver_info'])) {
                $data['reciver_info'] = $post['reciver_info'];
            }
            $data['deliver_explain'] = $post['deliver_explain'];
            $data['daddress_id'] = intval($post['daddress_id']);
            $data['shipping_express_id'] = intval($post['shipping_express_id']);
            $data['shipping_time'] = TIMESTAMP;

            $condition = array();
            $condition['order_id'] = $order_id;
            $condition['store_id'] = $order_info['store_id'];
            $update = $model_order->editOrderCommon($data,$condition);
            if (!$update) {
                throw new Exception('操作失败');
            }

            $data = array();
            $data['shipping_code']  = $post['shipping_code'];
            $data['order_state'] = ORDER_STATE_SEND;
            $data['delay_time'] = TIMESTAMP;
            $update = $model_order->editOrder($data,$condition);
            if (!$update) {
                throw new Exception('操作失败');
            }
            $model_order->commit();
        } catch (Exception $e) {
            $model_order->rollback();
            return callback(false,$e->getMessage());
        }
        $express_info = array();
        if ($post['shipping_express_id']) {
            $express_info = Model('express')->getExpressInfo(intval($post['shipping_express_id']));
            //更新门店代收表发货信息
            if ($order_info['extend_order_common']['reciver_info']['dlyp']) {
                $data = array();
                $data['shipping_code'] = $post['shipping_code'];
                $data['order_sn'] = $order_info['order_sn'];
                $data['express_code'] = $express_info['e_code'];
                $data['express_name'] = $express_info['e_name'];
                Model('chain_order')->editDeliveryOrder($data,array('order_id' => $order_info['order_id']));
            }
        }

        //添加订单日志
        $data = array();
        $data['order_id'] = $order_id;
        $data['log_role'] = $role;
        $data['log_user'] = $user;
        $data['log_msg'] = '发出货物(编辑信息)';
        $data['log_orderstate'] = ORDER_STATE_SEND;
        $model_order->addOrderLog($data);

        // 发送买家消息
        $param = array();
        $param['code'] = 'order_deliver_success';
        $param['member_id'] = $order_info['buyer_id'];
        $param['param'] = array(
            'order_sn' => $order_info['order_sn'],
            'order_url' => urlShop('member_order', 'show_order', array('order_id' => $order_id))
        );
        $express_info['order_sn'] = $order_info['order_sn'];
        $express_info['shipping_code'] = $post['shipping_code'];
        if (empty($express_info['shipping_code'])) {
            $express_info['e_name'] = '无';
            $express_info['shipping_code'] = '无';
        }
        $param['param']['mp_array'] = Logic('wx_api')->getTemplateData($param['code'],$express_info);
        if ($order_info['order_state'] != ORDER_STATE_SEND) RealTimePush('sendMemberMsg', $param);

        return callback(true,'操作成功');
    }

    /**
     * 批量发货
     * @param array $order_info
     * @param string $role 操作角色 buyer、seller、admin、system 分别代表买家、商家、管理员、系统
     * @param string $user 操作人
     * @return array
     */
    public function batchChangeOrderSend($order_info, $role, $user = '', $post = array()){
        $order_id = $order_info['order_id'];
        $model_order = Model('order');
        try {
            $model_order->beginTransaction();
            $data = array();
            $data['deliver_explain'] = $post['deliver_explain'];
            $data['shipping_express_id'] = intval($post['shipping_express_id']);
            $data['shipping_time'] = TIMESTAMP;

            $condition = array();
            $condition['order_id'] = $order_id;
            $condition['store_id'] = $order_info['store_id'];
            $update = $model_order->editOrderCommon($data,$condition);
            if (!$update) {
                throw new Exception('操作失败');
            }

            $data = array();
            $data['shipping_code']  = $post['shipping_code'];
            $data['order_state'] = ORDER_STATE_SEND;
            $data['delay_time'] = TIMESTAMP;
            $update = $model_order->editOrder($data,$condition);
            if (!$update) {
                throw new Exception('操作失败');
            }
            $model_order->commit();
        } catch (Exception $e) {
            $model_order->rollback();
            return callback(false,$e->getMessage());
        }
        $express_info = array();
        if ($post['shipping_express_id']) {
            $express_info = Model('express')->getExpressInfo(intval($post['shipping_express_id']));
        }

        //添加订单日志
        $data = array();
        $data['order_id'] = $order_id;
        $data['log_role'] = 'seller';
        $data['log_user'] = $user;
        $data['log_msg'] = '发出货物(编辑信息)';
        $data['log_orderstate'] = ORDER_STATE_SEND;
        $model_order->addOrderLog($data);

        // 发送买家消息
        $param = array();
        $param['code'] = 'order_deliver_success';
        $param['member_id'] = $order_info['buyer_id'];
        $param['param'] = array(
            'order_sn' => $order_info['order_sn'],
            'order_url' => urlShop('member_order', 'show_order', array('order_id' => $order_id))
        );
        $express_info['order_sn'] = $order_info['order_sn'];
        $express_info['shipping_code'] = $post['shipping_code'];
        if (empty($express_info['shipping_code'])) {
            $express_info['e_name'] = '无';
            $express_info['shipping_code'] = '无';
        }
        $param['param']['mp_array'] = Logic('wx_api')->getTemplateData($param['code'],$express_info);
        if ($order_info['order_state'] != ORDER_STATE_SEND) RealTimePush('sendMemberMsg', $param);

        return callback(true,'操作成功');

    }


    /**
     * 收到货款
     * @param array $order_info
     * @param string $role 操作角色 buyer、seller、admin、system 分别代表买家、商家、管理员、系统
     * @param string $user 操作人
     * @return array
     */
    public function changeOrderReceivePay($order_list, $role, $user = '', $post = array()) {
        $model_order = Model('order');

        try {
            $model_order->beginTransaction();
            $pay_info = $model_order->getOrderPayInfo(array('pay_sn'=>$order_list[0]['pay_sn']));
            if ($pay_info) {
                if ($pay_info['api_pay_state'] == 1) {
                    return callback(true,'操作成功');
                }
                $pay_info = $model_order->getOrderPayInfo(array('pay_id'=>$pay_info['pay_id']), true,true);
                if ($pay_info['api_pay_state'] == 1) {
                    return callback(true,'操作成功');
                }
            }
            $model_pd = Model('predeposit');
            foreach($order_list as $order_info) {
                $order_id = $order_info['order_id'];
                if (!in_array($order_info['order_state'],array(ORDER_STATE_NEW))) continue;
                //下单，支付被冻结的充值卡
                $rcb_amount = floatval($order_info['rcb_amount']);
                if ($rcb_amount > 0) {
                    $data_pd = array();
                    $data_pd['member_id'] = $order_info['buyer_id'];
                    $data_pd['member_name'] = $order_info['buyer_name'];
                    $data_pd['amount'] = $rcb_amount;
                    $data_pd['order_sn'] = $order_info['order_sn'];
                    $model_pd->changeRcb('order_comb_pay',$data_pd);
                }

                //下单，支付被冻结的预存款
                $pd_amount = floatval($order_info['pd_amount']);
                if ($pd_amount > 0) {
                    $data_pd = array();
                    $data_pd['member_id'] = $order_info['buyer_id'];
                    $data_pd['member_name'] = $order_info['buyer_name'];
                    $data_pd['amount'] = $pd_amount;
                    $data_pd['order_sn'] = $order_info['order_sn'];
                    $model_pd->changePd('order_comb_pay',$data_pd);
                }

                //更新订单相关扩展信息
                $result = $this->_changeOrderReceivePayExtend($order_info,$post);
                if (!$result['state']) {
                    throw new Exception($result['msg']);
                }

                //添加订单日志
                $data = array();
                $data['order_id'] = $order_id;
                $data['log_role'] = $role;
                $data['log_user'] = $user;
                $data['log_msg'] = '收到货款(外部交易号:'.$post['trade_no'].')';
                $data['log_orderstate'] = ORDER_STATE_PAY;
                $insert = $model_order->addOrderLog($data);
                if (!$insert) {
                    throw new Exception('操作失败');
                }

                //更新订单状态
                $update_order = array();
                $update_order['order_state'] = ORDER_STATE_PAY;
                $update_order['payment_time'] = ($post['payment_time'] ? strtotime($post['payment_time']) : TIMESTAMP);
                $update_order['payment_code'] = $post['payment_code'];
                if ($post['trade_no'] != '') {
                    $update_order['trade_no'] = $post['trade_no'];
                }
                $condition = array();
                $condition['order_id'] = $order_info['order_id'];
                $condition['order_state'] = ORDER_STATE_NEW;
                $update = $model_order->editOrder($update_order,$condition);
                if (!$update) {
                    throw new Exception('操作失败');
                }
            }

            //更新支付单状态
            $data = array();
            $data['api_pay_state'] = 1;
            $update = $model_order->editOrderPay($data,array('pay_sn'=>$order_info['pay_sn']));
            if (!$update) {
                throw new Exception('更新支付单状态失败');
            }

            $model_order->commit();
        } catch (Exception $e) {
            $model_order->rollback();
            return callback(false,$e->getMessage());
        }

        foreach($order_list as $order_info) {

            $order_id = $order_info['order_id'];
            //支付成功发送买家消息
            $param = array();
            $param['code'] = 'order_payment_success';
            $param['member_id'] = $order_info['buyer_id'];
            $param['param'] = array(
                    'order_sn' => $order_info['order_sn'],
                    'order_url' => urlShop('member_order', 'show_order', array('order_id' => $order_info['order_id']))
            );
            $param['param']['mp_array'] = Logic('wx_api')->getTemplateData($param['code'],$order_info);
            RealTimePush('sendMemberMsg', $param);

            //非预定订单下单或预定订单全部付款完成
            if ($order_info['order_type'] != 2 || $order_info['if_send_store_msg_pay_success']) {
                //支付成功发送店铺消息
                $param = array();
                $param['code'] = 'new_order';
                $param['store_id'] = $order_info['store_id'];
                $param['param'] = array(
                        'order_sn' => $order_info['order_sn']
                );
                RealTimePush('sendStoreMsg', $param);
                //门店自提发送提货码
                if ($order_info['order_type'] == 3) {
                    $_code = rand(100000,999999);
                    $result = $model_order->editOrder(array('chain_code'=>$_code),array('order_id'=>$order_info['order_id']));
                    if (!$result) {
                        throw new Exception('订单更新失败');
                    }
                    $param = array();
                    $param['chain_code'] = $_code;
                    $param['order_sn'] = $order_info['order_sn'];
                    $param['buyer_phone'] = $order_info['buyer_phone'];
                    RealTimePush('sendChainCode', $param);
                }
            }
        }

        return callback(true,'操作成功');
    }

    /**
     * 更新订单相关扩展信息
     * @param unknown $order_info
     * @return unknown
     */
    private function _changeOrderReceivePayExtend($order_info, $post) {
        //预定订单收款
        if ($order_info['order_type'] == 2) {
            $result = Logic('order_book')->changeBookOrderReceivePay($order_info, $post);
        }
        if ($order_info['order_type'] == 4) {//拼团订单
            $model_pintuan = Model('p_pintuan');
            $model_pintuan->payOrder($order_info);
        }
        return callback(true);
    }

    /**
     * 买家订单详细
     */
    public function getMemberOrderInfo($order_id,$member_id) {
        $order_id = intval($order_id);
        $member_id = intval($member_id);
        if ($order_id <= 0) {
            return callback(false,'订单不存在');
        }

        $model_order = Model('order');
        $condition = array();
        $condition['order_id'] = $order_id;
        $condition['buyer_id'] = $member_id;
        $order_info = $model_order->getOrderInfo($condition,array('order_goods','order_common','store'));
        if (empty($order_info) || $order_info['delete_state'] == ORDER_DEL_STATE_DROP) {
            return callback(false,'订单不存在');
        }

        $model_refund_return = Model('refund_return');
        $order_list = array();
        $order_list[$order_id] = $order_info;
        $order_list = $model_refund_return->getGoodsRefundList($order_list,1);//订单商品的退款退货显示
        $order_info = $order_list[$order_id];
        $refund_all = $order_info['refund_list'][0];
        if (!empty($refund_all) && $refund_all['seller_state'] < 3) {//订单全部退款商家审核状态:1为待审核,2为同意,3为不同意
        } else {
            $refund_all = array();
        }

        //显示锁定中
        $order_info['if_lock'] = $model_order->getOrderOperateState('lock',$order_info);

        //显示取消订单
        $order_info['if_buyer_cancel'] = $model_order->getOrderOperateState('buyer_cancel',$order_info);

        //显示退款取消订单
        $order_info['if_refund_cancel'] = $model_order->getOrderOperateState('refund_cancel',$order_info);

        //显示投诉
        $order_info['if_complain'] = $model_order->getOrderOperateState('complain',$order_info);

        //显示收货
        $order_info['if_receive'] = $model_order->getOrderOperateState('receive',$order_info);

        //显示物流跟踪
        $order_info['if_deliver'] = $model_order->getOrderOperateState('deliver',$order_info);

        //显示评价
        $order_info['if_evaluation'] = $model_order->getOrderOperateState('evaluation',$order_info);

        //显示分享
        $order_info['if_share'] = $model_order->getOrderOperateState('share',$order_info);

        //显示系统自动取消订单日期
        if ($order_info['order_state'] == ORDER_STATE_NEW) {
            $order_info['order_cancel_day'] = $order_info['add_time'] + ORDER_AUTO_CANCEL_TIME * 3600;
        }

        //显示快递信息
        if ($order_info['shipping_code'] != '') {
            $express = rkcache('express',true);
            $order_info['express_info']['e_code'] = $express[$order_info['extend_order_common']['shipping_express_id']]['e_code'];
            $order_info['express_info']['e_name'] = $express[$order_info['extend_order_common']['shipping_express_id']]['e_name'];
            $order_info['express_info']['e_url'] = $express[$order_info['extend_order_common']['shipping_express_id']]['e_url'];
        }

        //显示系统自动收获时间
        if ($order_info['order_state'] == ORDER_STATE_SEND) {
            $order_info['order_confirm_day'] = $order_info['delay_time'] + ORDER_AUTO_RECEIVE_DAY * 24 * 3600;
        }

        //如果订单已取消，取得取消原因、时间，操作人
        if ($order_info['order_state'] == ORDER_STATE_CANCEL) {
            $order_info['close_info'] = $model_order->getOrderLogInfo(array('order_id'=>$order_info['order_id']),'log_id desc');
        }
        //查询消费者保障服务
        if (C('contract_allow') == 1) {
            $contract_item = Model('contract')->getContractItemByCache();
        }
        foreach ($order_info['extend_order_goods'] as $value) {
            $value['image_60_url'] = cthumb($value['goods_image'], 60, $value['store_id']);
            $value['image_240_url'] = cthumb($value['goods_image'], 240, $value['store_id']);
            $value['goods_type_cn'] = orderGoodsType($value['goods_type']);
            $value['goods_url'] = urlShop('goods','index',array('goods_id'=>$value['goods_id']));
            $value['refund'] = $value['refund'] ? 1 : 0;
            //处理消费者保障服务
            if (trim($value['goods_contractid']) && $contract_item) {
                $goods_contractid_arr = explode(',',$value['goods_contractid']);
                foreach ((array)$goods_contractid_arr as $gcti_v) {
                    $value['contractlist'][] = $contract_item[$gcti_v];
                }
            }
            if ($value['goods_type'] == 5) {
                $order_info['zengpin_list'][] = $value;
            } else {
                $order_info['goods_list'][] = $value;
            }
        }

        if (empty($order_info['zengpin_list'])) {
            $order_info['zengpin_list'] = array();
            $order_info['goods_count'] = count($order_info['goods_list']);
        } else {
            $order_info['goods_count'] = count($order_info['goods_list']) + 1;
        }

        //取得其它订单类型的信息
        $model_order->getOrderExtendInfo($order_info);

        //卖家发货信息
        if (!empty($order_info['extend_order_common']['daddress_id'])) {
            $daddress_info = Model('daddress')->getAddressInfo(array('address_id'=>$order_info['extend_order_common']['daddress_id']));
        } else {
            $daddress_info = array();
        }
        return callback(true,'',array('order_info'=>$order_info,'refund_all'=>$refund_all,'daddress_info'=>$daddress_info));
    }
}
