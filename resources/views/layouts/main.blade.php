<html lang="ru">
<head>
    <title>Синхронизатор товаров ВК  - @yield('title')</title>
    <link rel="stylesheet" href="/css/app.css?v=1.0">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <a class="navbar-brand" href="#">Синхронизатор</a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarText" aria-controls="navbarText" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarText">
        <ul class="navbar-nav mr-auto">
            <li><a class="nav-link logout-link" href="{{route('categories')}}">Категории из файла</a></li>
            <li><a class="nav-link logout-link" href="{{route('album')}}">Подборки из ВКонтакте</a></li>
        </ul>
        <span class="navbar-text">
          {{optional(app('request')->attributes->get('authData'))['full_name']}}
        </span>
        <a class="nav-link logout-link" href="{{route('vk.logout')}}">Выйти</a>
    </div>
</nav>

<div class="container">
    @yield('content')
</div>

</body>
</html>
