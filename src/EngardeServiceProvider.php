<?php namespace Tjphippen\Engarde;

use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\AliasLoader;

class EngardeServiceProvider extends ServiceProvider
{
    protected $defer = false;

    public function boot()
    {
        $this->publishes([
            __DIR__.'/config/config.php' => config_path('engarde.php'),
        ]);
    }

    public function register()
    {
        $this->app->singleton('engarde', function ($app)
        {
            return new Engarde($app->config->get('engarde', []));
        });

        $this->app->booting(function()
        {
            AliasLoader::getInstance()->alias('Engarde', 'Tjphippen\Engarde\Facades\Engarde');
        });
    }

    public function provides()
    {
        return ['engarde'];
    }

}