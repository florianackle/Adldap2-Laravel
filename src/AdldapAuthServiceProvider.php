<?php

namespace Adldap\Laravel;

use RuntimeException;
use Adldap\AdldapInterface;
use Adldap\Laravel\Resolvers\UserResolver;
use Adldap\Laravel\Resolvers\ResolverInterface;
use Adldap\Laravel\Commands\Console\Import;
use Adldap\Laravel\Auth\DatabaseUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Authenticated;

class AdldapAuthServiceProvider extends ServiceProvider
{
    /**
     * Run service provider boot operations.
     *
     * @return void
     */
    public function boot()
    {
        $config = __DIR__.'/Config/auth.php';

        $this->publishes([
            $config => config_path('ldap_auth.php'),
        ]);

        $auth = Auth::getFacadeRoot();

        if (method_exists($auth, 'provider')) {
            $auth->provider('ldap', function ($app, array $config) {
                return $this->makeUserProvider($app['hash'], $config);
            });
        } else {
            $auth->extend('ldap', function ($app) {
                return $this->makeUserProvider($app['hash'], $app['config']['auth']);
            });
        }

        $this->commands(Import::class);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // Bind the user resolver instance into the IoC.
        $this->app->bind(ResolverInterface::class, function () {
            $ad = $this->app->make(AdldapInterface::class);

            return new UserResolver($ad);
        });

        // Here we will register the event listener that will bind the users LDAP
        // model to their Eloquent model upon authentication (if configured).
        // This allows us to utilize their LDAP model right
        // after authentication has passed.
        Event::listen([Login::class, Authenticated::class], Listeners\BindsLdapUserModel::class);

        if ($this->isLogging()) {
            // If logging is enabled, we will set up our event listeners that
            // log each event fired throughout the authentication process.
            foreach ($this->getLoggingEvents() as $event => $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    /**
     * Returns a new Adldap user provider.
     *
     * @param Hasher $hasher
     * @param array  $config
     *
     * @throws RuntimeException
     * 
     * @return \Illuminate\Contracts\Auth\UserProvider
     */
    protected function makeUserProvider(Hasher $hasher, array $config)
    {
        $provider = Config::get('ldap_auth.provider', DatabaseUserProvider::class);

        // The DatabaseUserProvider requires a model to be configured
        // in the configuration. We will validate this here.
        if (is_a($provider, DatabaseUserProvider::class, $allowString = true)) {
            // We will try to retrieve their model from the config file,
            // otherwise we will try to use the providers config array.
            $model = Config::get('ldap_auth.model') ?? array_get($config, 'model');

            if (!$model) {
                throw new RuntimeException(
                    "No model is configured. You must configure a model to use with the {$provider}."
                );
            }

            return new $provider($hasher, $model);
        }
        
        return new $provider;
    }

    /**
     * Determines if authentication requests are logged.
     *
     * @return bool
     */
    protected function isLogging()
    {
        return Config::get('ldap_auth.logging.enabled', false);
    }

    /**
     * Returns the configured authentication events to log.
     *
     * @return array
     */
    protected function getLoggingEvents()
    {
        return Config::get('ldap_auth.logging.events', []);
    }
}
