# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

FIDC Credit Risk Monitor — a dashboard for monitoring FIDC (Fundos de Investimento em Direitos Creditórios) exposure data from Brazil's CVM (securities regulator). The pipeline ingests public CVM CSV reports, processes them into a MySQL database, and serves a PHP dashboard.

## Common Commands

```bash
pip install -r requirements.txt

python carga_dados.py          # baixa ZIP da CVM e carrega no MySQL
python cedentes.py             # processa tabelas limpas

uvicorn api:app --reload       # API em http://localhost:8000 (docs em /docs)
```

MySQL runs via XAMPP (localhost:3306, user: root, no password, database: fidc).

## Architecture

**Data flow:** CVM website (ZIP) → `carga_dados.py` → `dados/*.csv` → MySQL raw tables → `cedentes.py` → `cronograma_cedentes` table → `index.php` dashboard

- `config.py` — single `DB_URL` constant (imported by both Python scripts)
- `carga_dados.py` — ETL extraction: auto-downloads the most recent ZIP from CVM (`https://dados.cvm.gov.br/dados/FIDC/DOC/INF_MENSAL/DADOS/inf_mensal_fidc_YYYYMM.zip`), extracts CSVs to `dados/`, loads into MySQL. Writes `.mes_atual` with the detected YYYYMM.
- `cedentes.py` — ETL transformation: joins Tab I (cedentes) with Tab V (amortization schedule), computes weighted values per maturity bucket. Writes two tables:
  - `cronograma_cedentes` — tabela legada com todas as colunas (usada pelo PHP)
  - `cedentes_cronograma` — tabela limpa: apenas cedente, fundo, participação e 8 buckets de pagamento + VL_TOTAL. Linhas com CNPJ_CEDENTE inválido são removidas.
- `api.py` — FastAPI backend (`uvicorn api:app --reload`). Endpoints: `GET /cedentes` (lista agregada), `GET /cedentes/{cnpj}` (detalhe por fundo), `GET /resumo` (KPIs + top 10). Swagger em `/docs`.
- `index.php` — (legado) PHP dashboard com KPI cards + tabela (Tailwind CSS)
- `dados/` — raw CVM CSV files (semicolon-delimited, latin1 encoding, Brazilian number format)

### Cedente extraction logic

`cedentes.py` dynamically scans Tab I columns `TAB_I2{A,B}12_CPF_CNPJ_CEDENTE_{1..14}` and their matching `_PR_CEDENTE_` percentage columns. It unpivots these into a flat cedentes table before merging with Tab V on `CNPJ_FUNDO_CLASSE`. Duplicate columns from Tab V are dropped before the merge to avoid `_x`/`_y` suffixes.

### Key business rules

- **Financial unit:** all values in thousands (R$ mil) — raw values divided by 1000
- **Risk alert:** cedentes with `PERC_PARTICIPACAO > 20%` get red visual highlight
- **Number format:** integers only, dot as thousand separator, no currency prefix/suffix
- **Amortization buckets:** `VALOR_CEDENTE_1` through `VALOR_CEDENTE_8` = <=30d, 31-60d, 61-90d, 91-120d, 121-150d, 151-180d, 181-360d, >360d

### Data format notes

- CVM CSVs: `sep=';'`, `encoding='latin1'`, Brazilian number format (`1.200,50` = 1200.50)
- `limpar_valor()` handles BR→float conversion; `limpar_cnpj()` strips non-numeric chars
- Column names are normalized to uppercase with quotes stripped on load
- PHP uses PDO with `mysql:` DSN, backtick identifiers, `LIKE` (not `ILIKE`)

### Month detection

The month is detected automatically — `carga_dados.py` probes the CVM server starting from the current month and going back up to 6 months. The detected YYYYMM is saved to `.mes_atual`, which `cedentes.py` reads to know which tables to query. No manual month updates needed.
