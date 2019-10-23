@extends('layouts.auth')

@section('title', 'Укажите группу!')

@section('content')
    <h1>Для дальнейшей работы с синхронизатором необходимо выбрать группу, в которой будут находиться товары</h1>
    <form method="post" action="{{route('auth.set.group')}}">

        {{ csrf_field() }}
        <div class="form-group">
            <div class="row justify-content-center">
            <select name="group_id" class="form-control w-25 text-center">
                @foreach($groups as $group)
                    <option value="{{$group['id']}}">{{$group['name']}}</option>
                @endforeach
            </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Сохранить в настройках</button>
    </form>
@endsection
