# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

FIDC Credit Risk Monitor — a dashboard for monitoring FIDC (Fundos de Investimento em Direitos Creditórios) exposure data from Brazil's CVM (securities regulator). The pipeline ingests public CVM CSV reports, processes them into a MySQL database, and serves a PHP dashboard.

## Common Commands

```bash
# Step 1: Load raw CVM CSVs into MySQL
python carga_dados.py

# Step 2: Process/transform data (cross-reference cedentes with amortization schedule)
python cedentes.py

# View dashboard
# Open browser: http://localhost/fidc/
```

MySQL runs via XAMPP (localhost:3306, user: root, no password, database: fidc).

## Architecture

**Data flow:** `dados/*.csv` → `carga_dados.py` → MySQL raw tables → `cedentes.py` → `cronograma_cedentes` table → `index.php` dashboard

### Files

- `config.py` — single source of truth for `DB_URL` (imported by both Python scripts)
- `carga_dados.py` — ETL extraction: reads CVM CSVs from `dados/`, loads into MySQL as-is
- `cedentes.py` — ETL transformation: joins Tab I (cedentes) with Tab V (amortization schedule), computes weighted values per maturity bucket, writes `cronograma_cedentes`
- `index.php` — PHP dashboard: reads `cronograma_cedentes`, renders KPI cards + searchable table
- `dados/` — raw CVM CSV files (semicolon-delimited, latin1 encoding, Brazilian number format)

### Key business rules

- **Financial unit:** all values displayed in thousands (R$ mil) — raw values divided by 1000
- **Risk alert:** cedentes with `PERC_PARTICIPACAO > 20%` get red visual highlight
- **Number format:** integers only, dot as thousand separator, no currency prefix
- **Amortization buckets:** `VALOR_CEDENTE_1` through `VALOR_CEDENTE_8` = ≤30d, 31-60d, 61-90d, 91-120d, 121-150d, 151-180d, 181-360d, >360d

### MySQL/PHP notes

- PHP uses PDO with `mysql:` DSN, backticks for identifiers, `LIKE` (not `ILIKE`)
- Python uses SQLAlchemy + pymysql driver
- CVM CSVs: `sep=';'`, `encoding='latin1'`, numbers use Brazilian format (`1.200,50` = 1200.50)
- `limpar_valor()` in `cedentes.py` handles the BR number format conversion
- `limpar_cnpj()` strips non-numeric chars before joining tables on CNPJ

### When updating for a new month

Change the month suffix (e.g., `202602` → `202603`) in two places:
1. `carga_dados.py` — the `arquivos` dict (filename → table name mapping)
2. `cedentes.py` — the two `pd.read_sql()` table names
