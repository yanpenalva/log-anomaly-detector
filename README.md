# php-log-anomaly-detector

Detecção de anomalias em logs HTTP com **PHP 8.4 + Flight PHP + PHP-ML + SQLite3**.
Projeto de estudo prático de Machine Learning clássico (clustering densidade-based),
feature engineering, encoding, normalização, persistência e arquitetura desacoplada.

```text
HTTP Logs
    ↓
Feature Extraction
    ↓
Encoding
    ↓
Normalization
    ↓
DBSCAN
    ↓
Clusters / Noise
    ↓
SQLite
    ↓
Flight API
```

## Objetivo

Receber logs de requisições HTTP (`method`, `endpoint`, `status_code`,
`response_time`, `request_size`, `hour`), agrupar padrões de tráfego com
DBSCAN e reportar **noise points** — registros cuja vizinhança é densidade
insuficiente para pertencer a qualquer cluster — como anomalias.

## Arquitetura

Flight é apenas camada de entrada HTTP + bootstrap. Nenhuma regra de ML vive
em rotas ou controllers.

```text
app/
├── Application/Anomaly/       # AnalyzeLogs (use case: pipeline completo)
├── Domain/Anomaly/            # HttpLogEntry, FeatureVector, DbscanParameters,
│                              # DetectionResult, AnalysisResult, ports
│                              # (AnomalyDetector, CategoricalEncoder, Normalizer,
│                              #  repositórios, loader)
├── Infrastructure/
│   ├── MachineLearning/       # PHP-ML encapsulado (PhpMlDbscanDetector),
│   │                          # LogCategoricalEncoder, MinMaxNormalizer
│   ├── Log/                   # CsvHttpLogLoader (CSV → validação → HttpLogEntry[])
│   └── Persistence/           # SimplePdo + prepared statements
├── Controller/Api/            # HealthController, AnalysisController (thin)
├── config/                    # bootstrap, services (Dice DI), routes
└── Utils/                     # Config, Env, DatabaseFactory (do skeleton)

migrations/                    # SQL puro via `php runway migrate`
datasets/                      # development.csv (determinístico, seed 42)
scripts/generate_dataset.php   # gerador do dataset
tests/                         # PHPUnit (unit + integração)
```

O domínio **não importa nada do PHP-ML**: `PhpMlDbscanDetector` implementa a
port `AnomalyDetector` (interface no domínio). O mesmo vale para encoding,
normalização e persistência.

## Instalação

```bash
composer install
cp .env.example .env          # DB_DRIVER=sqlite
php runway migrate            # cria analysis_runs + log_entries
```

## Execução

```bash
composer start                # php -S localhost:8000 -t public
php runway migrate            # aplicar migrations pendentes
```

Para recomeçar do zero (*fresh migration*): apague `database.sqlite` e rode
`php runway migrate`.

## Testes

```bash
composer test                 # PHPUnit
composer analyse              # PHPStan nível 8
composer check                # ambos
```

Os testes de ML são determinísticos: datasets fixos em código e dataset de
desenvolvimento gerado com seed fixa.

## Dataset de desenvolvimento

`datasets/development.csv` (1.400 linhas) é gerado por
`php scripts/generate_dataset.php` (seed 42 → sempre idêntico). Contém 6
perfis de tráfego denso (`/users`, `/users/{id}`, `/payments`, `/health`,
`/api/search`, `/products/{id}`) e ~2,5% de anomalias injetadas em sub-tipos
esparsos: rajadas de erro 5xx, floods de payload grande, vulnerability
scanners (`/.env`, `/wp-admin.php`, …) em horas atípicas e requisições
slowloris.

## Feature engineering

Vetor de features por log (ordem fixa, 9+16+4 = 29 dimensões):

| Bloco | Features | Estratégia |
|---|---|---|
| `method` | 9 (um por verbo HTTP) | **one-hot** sobre enum fixo — sem ordinais falsos |
| `endpoint` | 16 buckets | **feature hashing** (`crc32(endpoint_normalizado) % 16`), determinístico, tolera categorias não vistas |
| `status_code` | 1 | numérico cru |
| `response_time` | 1 | numérico cru (ms) |
| `request_size` | 1 | numérico cru (bytes) |
| `hour` | 1 | numérico cru (0–23) |

Antes do hash, o endpoint é normalizado (`/users/1912` → `/users/{n}`) para
que URLs parametrizadas compartilhem densidade em vez de fragmentar.

### Normalização

**Min-max** por feature, fitted no batch inteiro (`Normalizer::fit()` →
`FittedNormalizer`). Os parâmetros aprendidos (`min`/`max` por dimensão)
são reaproveitados em todo `transform()` — nunca recomputados por registro.
Feature constante (`min == max`) mapeia para 0.

## DBSCAN

Implementação: `Phpml\Clustering\DBSCAN` (PHP-ML 0.10), distância Euclidiana
comparação de vizinhança **estrita** (`< epsilon`).

Parâmetros (config `anomaly.*`, sobrescrevíveis por request):

| Parâmetro | Default | Faixa |
|---|---|---|
| `epsilon` | 0.35 | 0.001–1000 |
| `minimum_samples` | 5 | 1–10000 |

Semântica (respeitando o algoritmo real):

- **core point**: ≥ `minimum_samples` vizinhos dentro de `epsilon`
  (incluindo ele próprio);
- **cluster**: núcleo + tudo densidade-reachable; ids atribuídos na ordem de
  descoberta (determinístico para ordem de entrada fixa);
- **noise point**: registro que não pertence a cluster nenhum → **anomalia**;
- **anomalia** = flag booleana. **Não existe** `confidence`, `probability`
  nem `accuracy`: DBSCAN não produz essas grandezas. A resposta diz
  `{"anomaly": true}` apenas quando é verdade.

Nota sobre a saída do PHP-ML: `DBSCAN::cluster()` renumera as chaves dos
membros (`array_merge` em `groupByCluster`), destruindo o índice original.
O wrapper reconstrói a atribuição por multiset (vetores idênticos recebem
sempre o mesmo label — distâncias idênticas a todos os pontos).

## Endpoints

| Método | Rota | Descrição |
|---|---|---|
| GET | `/api/v1/health` | liveness |
| POST | `/api/v1/analyze` | análise batch + persistência |
| GET | `/api/v1/analysis` | últimas análises (`?limit=1..200`) |
| GET | `/api/v1/analysis/{id}` | detalhe + anomalias (cap 100, flag `anomalies_truncated`) |
| POST | `/api/v1/detect` | **501** — ver Limitações |

### POST /api/v1/analyze

```json
{
  "logs": [
    {"method": "GET", "endpoint": "/users", "status_code": 200,
     "response_time": 118, "request_size": 1024, "hour": 10}
  ],
  "epsilon": 0.35,
  "minimum_samples": 5
}
```

Resposta:

```json
{"data": {"run_id": 5, "algorithm": "dbscan", "epsilon": 0.4,
          "minimum_samples": 5, "samples": 133, "clusters": 3, "anomalies": 3}}
```

Erros: JSON inválido → 400; corpo não-objeto → 400; campo de log inválido →
422 (com índice do log); excesso de logs/payload → 422/413. Limites em
`anomaly.max_payload_bytes` (2 MiB) e `anomaly.max_logs_per_request` (10k).
A API **nunca** aceita caminho de arquivo — só logs inline.

## Persistência

SQLite via `flight\database\SimplePdo` (PDO) + prepared statements.
Tabelas (ver `migrations/`):

- `analysis_runs`: `id, algorithm, epsilon, minimum_samples, sample_count,
  cluster_count, anomaly_count, started_at, finished_at`
- `log_entries`: `id, analysis_run_id, method, endpoint, status_code,
  response_time, request_size, hour, is_anomaly, cluster (NULL = noise),
  created_at`

Sem ORM. Sem arrays serializados. Sem tabela `models` (não há estado de
modelo treinado para persistir nesta versão).

## Limitações (explícitas)

1. **`/detect` não existe de verdade (501)**: DBSCAN é algoritmo batch — não
   há estado treinado que classifique um ponto novo incrementalmente. A
   extensão tecnicamente correta seria persistir os vetores normalizados do
   treino e classificar um ponto novo consultando sua vizinhança-ε
   (anomalia se vizinhos < `minimum_samples`). Projetado, não implementado.
2. **Colisões de hash**: endpoints distintos podem cair no mesmo bucket
   (hashing trick); com buckets=16 e cardinalidade baixa de paths
   normalizados, impacto prático pequeno.
3. **`hour` linear**: 23 e 0 ficam distantes. Encoding cíclico (sin/cos)
   foi testado e rejeitado nesta configuração: com min-max + Euclidiana
   espalha horas do mesmo perfil além de ε e fragmenta clusters.
4. **Min-max é sensível a outliers extremos** (estica a escala). Z-score ou
   robust scaling são alternativas naturais.
5. **DBSCAN por batch**: cada análise re-clusteriza tudo. Não há
   aprendizado incremental.

## Roadmap

- **V1** ✅ remoção do boilerplate, SQLite, dataset CSV, domínio, features,
  encoding, normalização, DBSCAN, testes
- **V2** ✅ persistência das análises, `/health`, `/analyze`, erros globais
  tipados por endpoint
- **V3** parser de access.log do Nginx, batch analysis via CLI
- **V4** comparar DBSCAN × K-Means (e outros do PHP-ML) com métricas
  adequadas a cada família (densidade × centróide)
- **V5** benchmarking, estatísticas, visualização, dashboard opcional
