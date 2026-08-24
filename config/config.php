<?php
return array (
  'app_name' => 'KIrva 小说助手',
  'version' => '1.1.7',
  'app_key' => '48abab21bba2c15d3ff4f9e76f04ccca',
  'installed_at' => '2026-08-24 10:52:57',
  'update_check_url' => 'https://example.com/klrvai/version.json',

  // 当前使用的数据库类型：sqlite 或 mysql
  'db_driver' => 'mysql',   // 可改为 sqlite 切换

  // SQLite 配置
  'sqlite' => array (
    'driver'   => 'sqlite',
    'database' => '/workspace/data/klrvai_novel.sqlite',
    'prefix'   => 'kl_',
  ),

  // MySQL 配置
  'mysql' => array (
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'aixiaoshuo',
    'username' => 'aixiaoshuo',
    'password' => 'aixiaoshuo',
    'prefix'   => 'kl_',
    'charset'  => 'utf8mb4',
    'collation'=> 'utf8mb4_unicode_ci',
  ),
);
