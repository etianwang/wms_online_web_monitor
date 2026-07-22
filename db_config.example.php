<?php
/**
 * 复制本文件为 db_config.php 并填入真实连接信息。
 * db_config.php 已加入 .gitignore，请勿提交仓库。
 *
 * region 键：
 *   ci     = 科特迪瓦（阿里云）
 *   ci_jz  = 科特迪瓦精装（Neon）
 *   cm     = 喀麦隆（Neon）
 */
return array(
    'ci' => array(
        'label'    => '科特迪瓦',
        'host'     => 'YOUR_ALIYUN_HOST',
        'port'     => '5432',
        'dbname'   => 'postgres',
        'user'     => 'YOUR_USER',
        'password' => 'YOUR_PASSWORD',
        'timeout'  => 8,
        'sslmode'  => '',
    ),
    'ci_jz' => array(
        'label'    => '科特迪瓦精装',
        'host'     => 'YOUR_NEON_HOST',
        'port'     => '5432',
        'dbname'   => 'neondb',
        'user'     => 'YOUR_USER',
        'password' => 'YOUR_PASSWORD',
        'timeout'  => 8,
        'sslmode'  => 'require',
    ),
    'cm' => array(
        'label'    => '喀麦隆',
        'host'     => 'YOUR_NEON_HOST',
        'port'     => '5432',
        'dbname'   => 'neondb',
        'user'     => 'YOUR_USER',
        'password' => 'YOUR_PASSWORD',
        'timeout'  => 8,
        'sslmode'  => 'require',
    ),
);
