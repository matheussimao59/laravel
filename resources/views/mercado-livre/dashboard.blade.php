@extends('layouts.app')

@section('title', 'Mercado Livre')

@section('content')
<div class="container">
    <div class="page-head">
        <h1>Dashboard Mercado Livre</h1>
        <p>Modulo atual de repricing e gestao de produtos do Mercado Livre.</p>
    </div>

    <div class="grid">
        <div class="card">
            <div class="card-body">
                <p class="metric-label">Total de produtos</p>
                <h2 class="metric-value">{{ $totalProducts }}</h2>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <p class="metric-label">Com repricing automatico</p>
                <h2 class="metric-value">{{ $autoRepriceCount }}</h2>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <p class="metric-label">Mudancas recentes</p>
                <h2 class="metric-value">{{ $recentChanges->count() }}</h2>
            </div>
        </div>
    </div>

    <section class="card">
        <div class="card-content">
            <h3 class="panel-title">Produtos</h3>
            @if($products->count() > 0)
                <p class="panel-subtitle">
                    Exibindo {{ $products->firstItem() }}-{{ $products->lastItem() }} de {{ $totalProducts }} produtos.
                </p>
            @endif
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Titulo</th>
                            <th>Preco atual</th>
                            <th>Margem</th>
                            <th>Auto reprice</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($products as $product)
                            <tr>
                                <td>{{ $product->title }}</td>
                                <td>R$ {{ number_format($product->current_price, 2, ',', '.') }}</td>
                                <td>{{ number_format($product->calculateMargin(), 2) }}%</td>
                                <td>{{ $product->auto_reprice ? 'Sim' : 'Nao' }}</td>
                                <td>
                                    <form action="{{ route('mercado-livre.update-price', $product->id) }}" method="POST" class="inline-form">
                                        @csrf
                                        @method('PATCH')
                                        <input type="number" step="0.01" name="new_price" value="{{ $product->current_price }}" class="form-control" required>
                                        <button type="submit" class="btn btn-primary">Atualizar</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">Nenhum produto cadastrado ainda.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($products->hasPages())
                <div class="pagination-wrap">
                    <div class="actions">
                        @if($products->previousPageUrl())
                            <a href="{{ $products->previousPageUrl() }}" class="btn btn-light">Pagina anterior</a>
                        @endif
                        <span class="muted">Pagina {{ $products->currentPage() }}</span>
                        @if($products->nextPageUrl())
                            <a href="{{ $products->nextPageUrl() }}" class="btn btn-light">Proxima pagina</a>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </section>

    <div class="grid">
        <section class="card">
            <div class="card-content">
                <h3 class="panel-title">Adicionar produto</h3>
                <form action="{{ route('mercado-livre.store') }}" method="POST" class="stack">
                    @csrf
                    <div class="form-grid">
                        <div class="field">
                            <label for="item_id">Item ID</label>
                            <input id="item_id" type="text" name="item_id" class="form-control" required>
                        </div>
                        <div class="field">
                            <label for="title">Titulo</label>
                            <input id="title" type="text" name="title" class="form-control" required>
                        </div>
                        <div class="field">
                            <label for="current_price">Preco atual</label>
                            <input id="current_price" type="number" step="0.01" name="current_price" class="form-control" required>
                        </div>
                        <div class="field">
                            <label for="cost_price">Custo</label>
                            <input id="cost_price" type="number" step="0.01" name="cost_price" class="form-control">
                        </div>
                        <div class="field">
                            <label for="min_margin">Margem minima (%)</label>
                            <input id="min_margin" type="number" step="0.01" name="min_margin" value="10" class="form-control">
                        </div>
                    </div>
                    <div class="actions">
                        <button type="submit" class="btn btn-success">Adicionar</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card-content">
                <h3 class="panel-title">Mudancas recentes</h3>
                <ul class="list">
                    @forelse($recentChanges as $change)
                        <li>{{ $change->mercadoLivreProduct->title }}: R$ {{ $change->old_price }} -> R$ {{ $change->new_price }} ({{ $change->reason }})</li>
                    @empty
                        <li>Nenhuma mudanca recente registrada.</li>
                    @endforelse
                </ul>
            </div>
        </section>
    </div>
</div>
@endsection
