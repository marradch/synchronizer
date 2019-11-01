@extends('layouts.main')

@section('title', 'Дашборд')

@section('content')
    <h1>Подборки из ВКонтакте</h1>
    <div id="app">
        <albums-list></albums-list>
    </div>
    <script src="/js/app.js?v=1.0"></script>
@endsection
