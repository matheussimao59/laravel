@extends('layouts.app')

@section('title', 'Mercado livre beta')

@section('content')
<div class="container">
    <div class="page-head">
        <span class="badge badge-warning">Ambiente separado</span>
        <h1>Mercado livre beta</h1>
        <p>
            Esta area fica isolada da funcao atual do Mercado Livre. Ela serve como base para testar o novo sistema
            sem misturar o fluxo antigo com o beta.
        </p>
    </div>

    <div class="grid">
        <article class="card">
            <div class="card-body">
                <p class="metric-label">Nome do sistema</p>
                <h2 class="metric-value">Mercado livre beta</h2>
            </div>
        </article>
        <article class="card">
            <div class="card-body">
                <p class="metric-label">Objetivo</p>
                <h2 class="metric-value">Separar evolucao</h2>
            </div>
        </article>
        <article class="card">
            <div class="card-body">
                <p class="metric-label">Status</p>
                <h2 class="metric-value">Pronto para montar</h2>
            </div>
        </article>
    </div>

    <div class="grid">
        <section class="card">
            <div class="card-content stack">
                <div>
                    <h3 class="panel-title">Como este beta foi separado</h3>
                    <p class="muted">
                        O menu, a rota e a tela inicial agora sao proprios. Isso permite criar novas regras, novos cards
                        e novas automacoes sem impactar o modulo atual.
                    </p>
                </div>
                <ul class="list">
                    <li>Rota dedicada para o beta.</li>
                    <li>Nome proprio no menu: <strong>Mercado livre beta</strong>.</li>
                    <li>Estrutura pronta para crescer como modulo separado.</li>
                </ul>
            </div>
        </section>
        <section class="card">
            <div class="card-content stack">
                <div>
                    <h3 class="panel-title">Proximo passo recomendado</h3>
                    <p class="muted">
                        A partir daqui podemos montar o beta com dashboard proprio, cards novos, regras de repricing,
                        alertas de margem e simulacao de preco, tudo sem tocar no fluxo antigo.
                    </p>
                </div>
                <div class="actions">
                    <a href="{{ route('mercado-livre.dashboard') }}" class="btn btn-light">Abrir modulo atual</a>
                </div>
            </div>
        </section>
    </div>
</div>
@endsection
