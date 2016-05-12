<?php

namespace tests\codeception\common\unit;

use tests\codeception\common\PrivateTestTrait;

/**
 * @inheritdoc
 */
class TestCase extends \yii\codeception\TestCase
{
    use PrivateTestTrait;
    
    public $appConfig = '@tests/codeception/config/common/unit.php';
}
