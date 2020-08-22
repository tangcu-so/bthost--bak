define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'host/index' + location.search,
                    add_url: 'host/add',
                    edit_url: 'host/edit',
                    del_url: 'host/del',
                    multi_url: 'host/multi',
                    import_url: 'host/import',
                    table: 'host',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [
                    [
                        {checkbox: true},
                        // {field: 'id', title: __('Id')},
                        {field: 'user.username', title: __('User_id')},
                        {field: 'sort_id', title: __('Sort_id')},
                        {field: 'bt_id', title: __('Bt_id')},
                        {field: 'bt_name', title: __('Bt_name')},
                        {field: 'site_size', title: __('Site'), formatter: function (value, row, index) { 
                            return row.site_max==0?'无限制':'<progress value="'+row.site_size+'" max="'+row.site_max+'" title="'+row.site_size+'/'+row.site_max+'"></progress>';
                        }},
                        {field: 'flow_size', title: __('Flow'), formatter: function (value, row, index) { 
                            return row.flow_max==0?'无限制':'<progress value="'+row.flow_size+'" max="'+row.flow_max+'" title="'+row.flow_size+'/'+row.flow_max+'"></progress>';
                        }},
                        {field: 'sql_size', title: __('Sql'), formatter: function (value, row, index) { 
                            return row.sql_max==0?'无限制':'<progress value="'+row.sql_size+'" max="'+row.sql_max+'" title="'+row.sql_size+'/'+row.sql_max+'"></progress>';
                        }},
                        {field: 'ip_address', title: __('Ip_address')},
                        {field: 'domain_max', title: __('Domain_max')},
                        {field: 'default_analysis', title: __('Default_analysis')},
                        {field: 'is_audit', title: __('Is_audit'), searchList: {"0":__('Audit 0'),"1":__('Audit 1')}, formatter: Table.api.formatter.normal},
                        {field: 'check_time', title: __('Check_time'), operate:'RANGE', addclass:'datetimerange', formatter: Table.api.formatter.datetime},
                        {field: 'analysis_type', title: __('Analysis_type')},
                        {field: 'web_back_num', title: __('Web_back_num')},
                        {field: 'sql_back_num', title: __('Sql_back_num')},
                        {field: 'is_vsftpd', title: __('Is_vsftpd'), searchList: {"0":__('Is_vsftpd 0'),"1":__('Is_vsftpd 1')}, formatter: Table.api.formatter.normal},
                        {field: 'createtime', title: __('Createtime'), operate:'RANGE', addclass:'datetimerange', formatter: Table.api.formatter.datetime},
                        {field: 'updatetime', title: __('Updatetime'), operate:'RANGE', addclass:'datetimerange', formatter: Table.api.formatter.datetime},
                        {field: 'endtime', title: __('Endtime'), operate:'RANGE', addclass:'datetimerange', formatter: Table.api.formatter.datetime},
                        {field: 'status', title: __('Status'), searchList: {"normal":__('Status normal'),"stop":__('Status stop'),"locked":__('Status locked'),"expired":__('Status expired'),"excess":__('Status excess'),"error":__('Status error')}, formatter: Table.api.formatter.status},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        recyclebin: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    'dragsort_url': ''
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: 'host/recyclebin' + location.search,
                pk: 'id',
                sortName: 'id',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id')},
                        {
                            field: 'deletetime',
                            title: __('Deletetime'),
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'operate',
                            width: '130px',
                            title: __('Operate'),
                            table: table,
                            events: Table.api.events.operate,
                            buttons: [
                                {
                                    name: 'Restore',
                                    text: __('Restore'),
                                    classname: 'btn btn-xs btn-info btn-ajax btn-restoreit',
                                    icon: 'fa fa-rotate-left',
                                    url: 'host/restore',
                                    refresh: true
                                },
                                {
                                    name: 'Destroy',
                                    text: __('Destroy'),
                                    classname: 'btn btn-xs btn-danger btn-ajax btn-destroyit',
                                    icon: 'fa fa-times',
                                    url: 'host/destroy',
                                    refresh: true
                                }
                            ],
                            formatter: Table.api.formatter.operate
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        add: function () {
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});