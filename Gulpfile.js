var elixir = require('laravel-elixir');

/*
 |----------------------------------------------------------------
 | Have a Drink!
 |----------------------------------------------------------------
 |
 | Elixir provides a clean, fluent API for defining some basic
 | Gulp tasks for your Laravel application. Elixir supports
 | several common CSS, JavaScript and even testing tools!
 |
 */

elixir(function(mix) {
    mix.less("bootstrap.less")
        .styles([
            'css/bootstrap.css',
            'css/custom.css',
            'css/select2.css',
            'css/select2-bootstrap.css'
        ]);
        //.routes()
        //.events()
        //.phpUnit();
});