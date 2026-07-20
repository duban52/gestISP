<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Title
    |--------------------------------------------------------------------------
    |
    | Here you can change the default title of your admin panel.
    |
    | For detailed instructions you can look the title section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Basic-Configuration
    |
    */

    'title' => 'gestISP',
    'title_prefix' => 'gestISP | ',
    'title_postfix' => '',

    /*
    |--------------------------------------------------------------------------
    | Favicon
    |--------------------------------------------------------------------------
    |
    | Here you can activate the favicon.
    |
    | For detailed instructions you can look the favicon section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Basic-Configuration
    |
    */

    'use_ico_only' => false,
    'use_full_favicon' => false,

    /*
    |--------------------------------------------------------------------------
    | Google Fonts
    |--------------------------------------------------------------------------
    |
    | Here you can allow or not the use of external google fonts. Disabling the
    | google fonts may be useful if your admin panel internet access is
    | restricted somehow.
    |
    | For detailed instructions you can look the google fonts section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Basic-Configuration
    |
    */

    'google_fonts' => [
        'allowed' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Panel Logo
    |--------------------------------------------------------------------------
    |
    | Here you can change the logo of your admin panel.
    |
    | For detailed instructions you can look the logo section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Basic-Configuration
    |
    */

    'logo' => '<b>Gest</b>ISP',
    'logo_img' => 'img/logo-gestisp-solo-imagen.png',
    'logo_img_class' => 'brand-image img-circle elevation-3',
    'logo_img_xl' => null,
    'logo_img_xl_class' => 'brand-image-xs',
    'logo_img_alt' => 'Admin Logo',

    /*
    |--------------------------------------------------------------------------
    | Authentication Logo
    |--------------------------------------------------------------------------
    |
    | Here you can setup an alternative logo to use on your login and register
    | screens. When disabled, the admin panel logo will be used instead.
    |
    | For detailed instructions you can look the auth logo section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Basic-Configuration
    |
    */

    'auth_logo' => [
        'enabled' => false,
        'img' => [
            'path' => 'img/logo-gestisp-solo-imagen.png',
            'alt' => 'Auth Logo',
            'class' => '',
            'width' => 50,
            'height' => 50,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Preloader Animation
    |--------------------------------------------------------------------------
    |
    | Here you can change the preloader animation configuration. Currently, two
    | modes are supported: 'fullscreen' for a fullscreen preloader animation
    | and 'cwrapper' to attach the preloader animation into the content-wrapper
    | element and avoid overlapping it with the sidebars and the top navbar.
    |
    | For detailed instructions you can look the preloader section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Basic-Configuration
    |
    */

    'preloader' => [
        'enabled' => true,
        'mode' => 'fullscreen',
        'img' => [
            'path' => 'img/logo-gestisp-solo-imagen.png',
            'alt' => 'AdminLTE Preloader Image',
            'effect' => 'animation__shake',
            'width' => 60,
            'height' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Menu
    |--------------------------------------------------------------------------
    |
    | Here you can activate and change the user menu.
    |
    | For detailed instructions you can look the user menu section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Basic-Configuration
    |
    */

    'usermenu_enabled' => true,
    'usermenu_header' => false,
    'usermenu_header_class' => 'bg-primary',
    'usermenu_image' => false,
    'usermenu_desc' => false,
    'usermenu_profile_url' => false,

    /*
    |--------------------------------------------------------------------------
    | Layout
    |--------------------------------------------------------------------------
    |
    | Here we change the layout of your admin panel.
    |
    | For detailed instructions you can look the layout section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Layout-and-Styling-Configuration
    |
    */

    'layout_topnav' => null,
    'layout_boxed' => null,
    'layout_fixed_sidebar' => null,
    'layout_fixed_navbar' => null,
    'layout_fixed_footer' => null,
    'layout_dark_mode' => null,

    /*
    |--------------------------------------------------------------------------
    | Authentication Views Classes
    |--------------------------------------------------------------------------
    |
    | Here you can change the look and behavior of the authentication views.
    |
    | For detailed instructions you can look the auth classes section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Layout-and-Styling-Configuration
    |
    */

    'classes_auth_card' => 'card-outline card-primary',
    'classes_auth_header' => '',
    'classes_auth_body' => '',
    'classes_auth_footer' => '',
    'classes_auth_icon' => '',
    'classes_auth_btn' => 'btn-flat btn-primary',

    /*
    |--------------------------------------------------------------------------
    | Admin Panel Classes
    |--------------------------------------------------------------------------
    |
    | Here you can change the look and behavior of the admin panel.
    |
    | For detailed instructions you can look the admin panel classes here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Layout-and-Styling-Configuration
    |
    */

    'classes_body' => '',
    'classes_brand' => '',
    'classes_brand_text' => '',
    'classes_content_wrapper' => '',
    'classes_content_header' => '',
    'classes_content' => '',
    'classes_sidebar' => 'sidebar-dark-primary elevation-4',
    'classes_sidebar_nav' => '',
    'classes_topnav' => 'navbar-white navbar-light',
    'classes_topnav_nav' => 'navbar-expand',
    'classes_topnav_container' => 'container',

    /*
    |--------------------------------------------------------------------------
    | Sidebar
    |--------------------------------------------------------------------------
    |
    | Here we can modify the sidebar of the admin panel.
    |
    | For detailed instructions you can look the sidebar section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Layout-and-Styling-Configuration
    |
    */

    'sidebar_mini' => 'lg',
    'sidebar_collapse' => false,
    'sidebar_collapse_auto_size' => false,
    'sidebar_collapse_remember' => false,
    'sidebar_collapse_remember_no_transition' => true,
    'sidebar_scrollbar_theme' => 'os-theme-light',
    'sidebar_scrollbar_auto_hide' => 'l',
    'sidebar_nav_accordion' => true,
    'sidebar_nav_animation_speed' => 300,

    /*
    |--------------------------------------------------------------------------
    | Control Sidebar (Right Sidebar)
    |--------------------------------------------------------------------------
    |
    | Here we can modify the right sidebar aka control sidebar of the admin panel.
    |
    | For detailed instructions you can look the right sidebar section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Layout-and-Styling-Configuration
    |
    */

    'right_sidebar' => false,
    'right_sidebar_icon' => 'fas fa-cogs',
    'right_sidebar_theme' => 'dark',
    'right_sidebar_slide' => true,
    'right_sidebar_push' => true,
    'right_sidebar_scrollbar_theme' => 'os-theme-light',
    'right_sidebar_scrollbar_auto_hide' => 'l',

    /*
    |--------------------------------------------------------------------------
    | URLs
    |--------------------------------------------------------------------------
    |
    | Here we can modify the url settings of the admin panel.
    |
    | For detailed instructions you can look the urls section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Basic-Configuration
    |
    */

    'use_route_url' => false,
    'dashboard_url' => '/',
    'logout_url' => 'logout',
    'login_url' => 'login',
    // Registro público deshabilitado: los usuarios se crean desde
    // el módulo de usuarios (la ruta register tampoco existe ya)
    'register_url' => false,
    'password_reset_url' => 'password/reset',
    'password_email_url' => 'password/email',
    'profile_url' => false,
    'disable_darkmode_routes' => false,

    /*
    |--------------------------------------------------------------------------
    | Laravel Asset Bundling
    |--------------------------------------------------------------------------
    |
    | Here we can enable the Laravel Asset Bundling option for the admin panel.
    | Currently, the next modes are supported: 'mix', 'vite' and 'vite_js_only'.
    | When using 'vite_js_only', it's expected that your CSS is imported using
    | JavaScript. Typically, in your application's 'resources/js/app.js' file.
    | If you are not using any of these, leave it as 'false'.
    |
    | For detailed instructions you can look the asset bundling section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Other-Configuration
    |
    */

    'laravel_asset_bundling' => false,
    'laravel_css_path' => 'css/app.css',
    'laravel_js_path' => 'js/app.js',

    /*
    |--------------------------------------------------------------------------
    | Menu Items
    |--------------------------------------------------------------------------
    |
    | Here we can modify the sidebar/top navigation of the admin panel.
    |
    | For detailed instructions you can look here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Menu-Configuration
    |
    */

    'menu' => [
        // Navbar items:
        [
            'type' => 'navbar-search',
            'text' => 'Búsqueda',
            'topnav_right' => false,
        ],
        [
            'type' => 'fullscreen-widget',
            'topnav_right' => true,
        ],

        // Sidebar items:
        [
            'type' => 'sidebar-menu-search',
            'text' => 'Búsqueda',
        ],

        [
            'text' => 'Informes gerenciales',
            'icon' => 'fas fa-chart-line',
            'can' => 'reports.index',
            'submenu' => [
                [
                    'text' => 'Tablero ejecutivo',
                    'route' => 'reports.index',
                    'active' => ['informes'],
                    'icon' => 'fas fa-tachometer-alt',
                    'can' => 'reports.index',
                ],
                [
                    'text' => 'Crecimiento',
                    'route' => 'reports.growth',
                    'active' => ['informes/crecimiento*'],
                    'icon' => 'fas fa-user-plus',
                    'can' => 'reports.growth',
                ],
                [
                    'text' => 'Órdenes técnicas',
                    'route' => 'reports.technical',
                    'active' => ['informes/tecnicas*'],
                    'icon' => 'fas fa-tools',
                    'can' => 'reports.technical',
                ],
                [
                    'text' => 'Facturación y recaudo',
                    'route' => 'reports.billing',
                    'active' => ['informes/facturacion*'],
                    'icon' => 'fas fa-file-invoice-dollar',
                    'can' => 'reports.billing',
                ],
                [
                    'text' => 'Aprovisionamiento',
                    'route' => 'reports.provisioning',
                    'active' => ['informes/aprovisionamiento*'],
                    'icon' => 'fas fa-network-wired',
                    'can' => 'reports.provisioning',
                ],
            ],
        ],

        [
            'text' => 'Gestión empresarial',
            'icon' => 'fas  fa-building',
            'submenu' => [
                [
                    'text' => 'Sucursales',
                    'route' => 'branches.index',
                    'active' => ['gestisp/branches*'],
                    'icon' => 'fas  fa-code-branch',
                    'can' => 'branches.index',
                ],
                [
                    'text' => 'Servicios',
                    'route' => 'services.index',
                    'active' => ['gestisp/services*'],
                    'icon' => 'fas  fa-wifi',
                    'can' => 'services.index',
                ],
                [
                    'text' => 'Planes de servicios',
                    'route' => 'plans.index',
                    'active' => ['gestisp/plans*'],
                    'icon' => 'fas  fa-box',
                    'can' => 'plans.index',
                ],
            ],
        ],

        [
            'text' => 'Gestión de clientes',
            'icon' => 'fas  fa-users',
            'submenu' => [
                [
                    'text' => 'Creación de cliente',
                    'route' => 'clients.create',
                    'active' => ['gestisp/clients/create'],
                    'icon' => 'fas  fa-plus-circle',
                    'can' => 'clients.create',
                ],
                [
                    'text' => 'Busqueda de cliente',
                    'route' => 'clients.search',
                    'active' => ['gestisp/clients/search*'],
                    'icon' => 'fas  fa-search',
                    'can' => 'clients.search',
                ],
                [
                    'text' => 'Listado de clientes',
                    'route' => 'contracts.index',
                    'active' => ['gestisp/contracts'],
                    'icon' => 'fas  fa-list',
                    'can' => 'contracts.index',
                ],

            ],
        ],

        [
            'text' => 'Facturación',
            'icon' => 'fas  fa-file-invoice-dollar',
            'submenu' => [
                [
                    'text' => 'Facturas',
                    'route' => 'invoices.index',
                    'icon' => 'fas  fa-receipt',
                    'can' => 'invoices.index',
                ],
                [
                    'text' => 'Cobranza',
                    'icon' => 'fas  fa-dollar-sign',
                    'submenu' => [
                        [
                            'text' => 'Gestión de caja',
                            'route' => 'cashRegisters.index',
                            'active' => ['gestisp/cashRgisters'],
                            'icon' => 'fas  fa-cash-register',
                            'can' => 'cashRegisters.index',

                        ],
                        [
                            'text' => 'Cobrar',
                            'route' => 'payments.search',
                            'active' => ['gestisp/payments/search'],
                            'icon' => 'fas  fa-hand-holding-usd',
                            'can' => 'payments.search',
                        ],
                        [
                            'text' => 'Movimientos de caja',
                            'route' => 'transactions.index',
                            'active' => ['gestisp/transactions'],
                            'icon' => 'fas  fa-coins',
                            'can' => 'transactions.index',
                        ],
                        [
                            'text' => 'Registro de pagos',
                            'route' => 'payments.index',
                            'active' => ['gestisp/payments'],
                            'icon' => 'fas  fa-receipt',
                            'can' => 'payments.index',
                        ],
                        [
                            'text' => 'Resumen de cajas',
                            'route' => 'cash_register.summary',
                            'active' => ['cash-register/summary'],
                            'icon' => 'fas  fa-balance-scale',
                            'can' => 'cash_register.summary',
                        ],

                    ]
                ],
            ],
        ],

        [
            'text' => 'Almacén',
            'icon' => 'fas  fa-store-alt',
            'submenu' => [
                [
                    'text' => 'Almacenes',
                    'route' => 'warehouses.index',
                    'active' => ['gestisp/warehouses*'],
                    'icon' => 'fas  fa-warehouse',
                    'can' => 'warehouses.index',
                ],
                [
                    'text' => 'Materiales',
                    'route' => 'materials.index',
                    'active' => ['gestisp/materials*'],
                    'icon' => 'fas  fa-hammer',
                    'can' => 'materials.index',
                ],
                [
                    'text' => 'Movimientos',
                    'route' => 'movements.index',
                    'active' => ['gestisp/movements/index'],
                    'icon' => 'fas  fa-exchange-alt',
                    'can' => 'movements.index',
                ],
                [
                    'text' => 'Historial de movimientos',
                    'route' => 'movements.history',
                    'active' => ['gestisp/movements/history'],
                    'icon' => 'fas  fa-history',
                    'can' => 'movements.history',
                ],

            ],
        ],

        [
            'text' => 'Gestión Técnica',
            'icon' => 'fas  fa-hard-hat',
            'submenu' => [
                [
                    'text' => 'Órdenes ténicas',
                    'route' => 'technicals_orders.index',
                    'active' => ['gestisp/technicals_orders*'],
                    'icon' => 'fas  fa-receipt',
                    'can' => 'technicals_orders.index',
                ],
                [
                    'text' => 'Mis Órdenes',
                    'route' => 'technicals_orders.my_technical_orders',
                    'active' => ['gestisp/technicals_orders*'],
                    'icon' => 'fas  fa-wrench',
                    'can' => 'technicals_orders.my_technical_orders',
                ],
                [
                    'text' => 'Verificación de órdenes',
                    'route' => 'technicals_orders.verification',
                    'active' => ['gestisp/technicals_orders*'],
                    'icon' => 'fas  fa-check-square',
                    'can' => 'technicals_orders.verification',
                ],
            ],


        ],

        [
            'text' => 'Aprovisionamiento',
            'icon' => 'fas  fa-wifi',
            'submenu' => [
                [
                    'text' => 'Routers',
                    'route' => 'routers.index',
                    'active' => ['gestisp/routers*'],
                    'icon' => 'fas  fa-ethernet',
                    'can' => 'routers.index',
                ],
                [
                    'text' => 'PPPoE',
                    'route' => 'pppoe.index',
                    'active' => ['gestisp/pppoe*'],
                    'icon' => 'fas  fa-user-check',
                    'can' => 'pppoe.index',
                ],
                [
                    'text' => 'OLT´s',
                    'route' => 'olts.index',
                    'active' => ['gestisp/olts*'],
                    'icon' => 'fas  fa-server',
                    'can' => 'olts.index',
                ],
                [
                    'text' => 'ONT´s',
                    'icon' => 'fas  fa-hdd',
                    'can' => 'onts.index',
                    'submenu' =>[
                        [
                            'text' => 'Por Autorizar',
                            'route' => 'onts.no-authorized',
                            'icon' => 'fas  fa-ban',
                            'active' => ['gestisp/onts/no-autorized'],
                            'can' => 'onts.index',
                        ],
                        [
                            'text' => 'Autorizadas',
                            'route' => 'onts.authorized',
                            'icon' => 'fas  fa-check-square',
                            'active' => ['gestisp/onts/authorized'],
                            'can' => 'onts.index',
                        ],
                        [
                            'text' => 'Importar de OLT',
                            'route' => 'onts.import.index',
                            'icon' => 'fas  fa-file-import',
                            'active' => ['onts/import*'],
                            'can' => 'onts.activate',
                        ],
                    ]
                ],
            ],


        ],

        [
            'text' => 'Gestión del sistema',
            'icon' => 'fas  fa-cogs',
            'submenu' => [
                [
                    'text' => 'Usuarios',
                    'route' => 'users.index',
                    'icon' => 'fas  fa-users-cog',
                    'active' => ['gestisp/users*'],
                    'can' => 'users.index',
                ],
                [
                    'text' => 'Roles',
                    'route' => 'roles.index',
                    'icon' => 'fas  fa-sliders-h',
                    'active' => ['gestisp/roles*'],
                    'can' => 'roles.index',
                ],

            ],

            ]


    ],

    /*
    |--------------------------------------------------------------------------
    | Menu Filters
    |--------------------------------------------------------------------------
    |
    | Here we can modify the menu filters of the admin panel.
    |
    | For detailed instructions you can look the menu filters section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Menu-Configuration
    |
    */

    'filters' => [
        // El GateFilter de AdminLTE se reemplazó por RoleBasedMenuFilter:
        // este evalúa 'can' contra el rol activo de la sesión (igual que
        // el middleware check.permission); el GateFilter lo hacía contra
        // todos los roles Spatie del usuario y ambos combinados
        // recortaban el menú de más.
        JeroenNoten\LaravelAdminLte\Menu\Filters\HrefFilter::class,
        JeroenNoten\LaravelAdminLte\Menu\Filters\SearchFilter::class,
        JeroenNoten\LaravelAdminLte\Menu\Filters\ActiveFilter::class,
        JeroenNoten\LaravelAdminLte\Menu\Filters\ClassesFilter::class,
        JeroenNoten\LaravelAdminLte\Menu\Filters\LangFilter::class,
        JeroenNoten\LaravelAdminLte\Menu\Filters\DataFilter::class,
        App\Filters\RoleBasedMenuFilter::class, // Filtro por rol de sesión
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugins Initialization
    |--------------------------------------------------------------------------
    |
    | Here we can modify the plugins used inside the admin panel.
    |
    | For detailed instructions you can look the plugins section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Plugins-Configuration
    |
    */

    'plugins' => [
        'Datatables' => [
            'active' => false,
            'files' => [
                [
                    'type' => 'js',
                    'asset' => false,
                    'location' => '//cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js',
                ],
                [
                    'type' => 'js',
                    'asset' => false,
                    'location' => '//cdn.datatables.net/1.10.19/js/dataTables.bootstrap4.min.js',
                ],
                [
                    'type' => 'css',
                    'asset' => false,
                    'location' => '//cdn.datatables.net/1.10.19/css/dataTables.bootstrap4.min.css',
                ],
            ],
        ],
        'Select2' => [
            'active' => false,
            'files' => [
                [
                    'type' => 'js',
                    'asset' => false,
                    'location' => '//cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/js/select2.min.js',
                ],
                [
                    'type' => 'css',
                    'asset' => false,
                    'location' => '//cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/css/select2.css',
                ],
            ],
        ],
        'Chartjs' => [
            'active' => false,
            'files' => [
                [
                    'type' => 'js',
                    'asset' => false,
                    'location' => '//cdnjs.cloudflare.com/ajax/libs/Chart.js/2.7.0/Chart.bundle.min.js',
                ],
            ],
        ],
        'Sweetalert2' => [
            'active' => false,
            'files' => [
                [
                    'type' => 'js',
                    'asset' => false,
                    'location' => '//cdn.jsdelivr.net/npm/sweetalert2@8',
                ],
            ],
        ],
        'Pace' => [
            'active' => false,
            'files' => [
                [
                    'type' => 'css',
                    'asset' => false,
                    'location' => '//cdnjs.cloudflare.com/ajax/libs/pace/1.0.2/themes/blue/pace-theme-center-radar.min.css',
                ],
                [
                    'type' => 'js',
                    'asset' => false,
                    'location' => '//cdnjs.cloudflare.com/ajax/libs/pace/1.0.2/pace.min.js',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | IFrame
    |--------------------------------------------------------------------------
    |
    | Here we change the IFrame mode configuration. Note these changes will
    | only apply to the view that extends and enable the IFrame mode.
    |
    | For detailed instructions you can look the iframe mode section here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/IFrame-Mode-Configuration
    |
    */

    'iframe' => [
        'default_tab' => [
            'url' => null,
            'title' => null,
        ],
        'buttons' => [
            'close' => true,
            'close_all' => true,
            'close_all_other' => true,
            'scroll_left' => true,
            'scroll_right' => true,
            'fullscreen' => true,
        ],
        'options' => [
            'loading_screen' => 1000,
            'auto_show_new_tab' => true,
            'use_navbar_items' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Livewire
    |--------------------------------------------------------------------------
    |
    | Here we can enable the Livewire support.
    |
    | For detailed instructions you can look the livewire here:
    | https://github.com/jeroennoten/Laravel-AdminLTE/wiki/Other-Configuration
    |
    */

    'livewire' => false,
];
