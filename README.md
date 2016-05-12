ActiveRecord Synchronization Behavior
=====================================
This behavior for automatic or manual sync data between two models, without declaration relation. This behavior must be attached on master model.  Main purposes - for sync rarely modified data from more reliable database storage to redis storage for frequently access; Support actual data state in some development cases;

Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
php composer.phar require --prefer-dist insolita/yii2-arsync "*"
```

or add

```
"insolita/yii2-arsync": "*"
```

to the require section of your `composer.json` file.


Usage
-----

Once the extension is installed, simply use it in your code by  :
See more in code
```php
public function behaviors(){
    return [
       'ArSyncBehavior'=>[
       				'class'      => ArSyncBehavior::class,
       				'slaveModel' => \your\model\namespase\Slave::className(),
       				'slaveScenario'=>'sync',
       				'errorSaveCallback'=>function($slave){
                          Yii::error(VarDumper::export($slave->errors));
                          throw new InvalidConfigException('fail save ');
                    },
                    'errorDeleteCallback'=>function($slave){
                        Yii::error('fail delete '.$slave->getPrimaryKey());
                     },
       				'fieldMap' => [
       					'id'=>'id',
       					'title' => 'name',
       					'foo'   => 'foo',
       					'bar'   => 'bar',
       					'baz'   => function($master)
       					{
       						return $master->baz * 2;
       					},
       				],
       			]
    ];
}
