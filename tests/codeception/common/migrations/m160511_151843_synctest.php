<?php

use yii\db\Migration;

class m160511_151843_synctest extends Migration
{
    public function safeUp()
    {
        $this->createTable('IF NOT EXISTS {{%master}}',[
            'id'=>$this->primaryKey(),
            'name'=>$this->string(20)->notNull(),
            'foo'=> $this->string(20)->notNull(),
            'bar'=> $this->string(20)->notNull()->defaultValue('masterdefault'),
            'baz'=> $this->integer(),
            'created'=>$this->timestamp(0)->defaultExpression('CURRENT_TIMESTAMP')
        ]);
        $this->createTable('IF NOT EXISTS {{%slave}}',[
            'id'=>$this->primaryKey(),
            'title'=>$this->string(20)->notNull(),
            'foo'=> $this->string(20)->notNull(),
            'bar'=> $this->string(20)->notNull()->defaultValue('slavedefault'),
            'baz'=> $this->integer(),
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('{{%master}}');
        $this->dropTable('{{%slave}}');
    }

}
