@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Dashboard Mercado Livre - Repricing</h1>

    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5>Total de Produtos</h5>
                    <h2>{{ $totalProducts }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5>Com Repricing Automático</h5>
                    <h2>{{ $autoRepriceCount }}</h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5>Mudanças Recentes</h5>
                    <h2>{{ $recentChanges->count() }}</h2>
                </div>
            </div>
        </div>
    </div>

    <h3>Produtos</h3>
    <table class="table">
        <thead>
            <tr>
                <th>Título</th>
                <th>Preço Atual</th>
                <th>Margem</th>
                <th>Auto Reprice</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            @foreach($products as $product)
            <tr>
                <td>{{ $product->title }}</td>
                <td>R$ {{ number_format($product->current_price, 2, ',', '.') }}</td>
                <td>{{ number_format($product->calculateMargin(), 2) }}%</td>
                <td>{{ $product->auto_reprice ? 'Sim' : 'Não' }}</td>
                <td>
                    <form action="{{ route('mercado-livre.update-price', $product->id) }}" method="POST" class="d-inline">
                        @csrf @method('PATCH')
                        <input type="number" step="0.01" name="new_price" value="{{ $product->current_price }}" required>
                        <button type="submit" class="btn btn-sm btn-primary">Atualizar</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <h3>Adicionar Produto</h3>
    <form action="{{ route('mercado-livre.store') }}" method="POST">
        @csrf
        <div class="mb-3">
            <label>Item ID</label>
            <input type="text" name="item_id" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Título</label>
            <input type="text" name="title" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Preço Atual</label>
            <input type="number" step="0.01" name="current_price" class="form-control" required>
        </div>
        <div class="mb-3">
            <label>Custo</label>
            <input type="number" step="0.01" name="cost_price" class="form-control">
        </div>
        <div class="mb-3">
            <label>Margem Mínima (%)</label>
            <input type="number" step="0.01" name="min_margin" value="10" class="form-control">
        </div>
        <button type="submit" class="btn btn-success">Adicionar</button>
    </form>

    <h3>Mudanças Recentes</h3>
    <ul>
        @foreach($recentChanges as $change)
        <li>{{ $change->mercadoLivreProduct->title }}: R$ {{ $change->old_price }} → R$ {{ $change->new_price }} ({{ $change->reason }})</li>
        @endforeach
    </ul>
</div>
@endsection