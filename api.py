from fastapi import FastAPI, Query
from fastapi.middleware.cors import CORSMiddleware
from sqlalchemy import create_engine, text
from config import DB_URL

app = FastAPI(title="FIDC Cedentes API")
app.add_middleware(CORSMiddleware, allow_origins=["*"], allow_methods=["*"], allow_headers=["*"])

engine = create_engine(DB_URL)


def _get_year_cols():
    """Descobre dinamicamente as colunas VL_XXXX da tabela."""
    with engine.connect() as conn:
        cols = conn.execute(text(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS "
            "WHERE TABLE_SCHEMA='fidc' AND TABLE_NAME='cedentes_cronograma' "
            "AND COLUMN_NAME LIKE 'VL_%' AND COLUMN_NAME != 'VL_TOTAL' "
            "ORDER BY COLUMN_NAME"
        )).scalars().all()
    return cols


@app.get("/colunas")
def colunas():
    """Retorna as colunas de ano disponíveis."""
    return _get_year_cols()


@app.get("/cedentes")
def listar_cedentes(search: str = Query(default="", description="Filtro por CNPJ")):
    """Lista cedentes com cronograma anual agregado."""
    year_cols = _get_year_cols()
    sums = ", ".join(f"SUM(c.`{c}`) AS `{c}`" for c in year_cols)
    query = f"""
        SELECT c.CNPJ_CEDENTE,
               COALESCE(n.RAZAO_SOCIAL, '') AS NOME_CEDENTE,
               COUNT(DISTINCT c.CNPJ_FUNDO) AS NUM_FUNDOS,
               SUM(c.VL_TOTAL) AS VL_TOTAL,
               {sums}
        FROM cedentes_cronograma c
        LEFT JOIN cnpj_nomes n ON c.CNPJ_CEDENTE = n.CNPJ
        WHERE (c.CNPJ_CEDENTE LIKE :search OR COALESCE(n.RAZAO_SOCIAL, '') LIKE :search)
        GROUP BY c.CNPJ_CEDENTE, n.RAZAO_SOCIAL
        HAVING ROUND(SUM(c.VL_TOTAL) / 1000) > 0
        ORDER BY VL_TOTAL DESC
    """
    with engine.connect() as conn:
        rows = conn.execute(text(query), {"search": f"%{search}%"}).mappings().all()
    return [dict(r) for r in rows]


@app.get("/cedentes/{cnpj}")
def detalhe_cedente(cnpj: str):
    """Detalhe de um cedente por fundo com cronograma anual."""
    year_cols = _get_year_cols()
    cols_sql = ", ".join(f"`{c}`" for c in year_cols)
    query = f"""
        SELECT CNPJ_FUNDO, NOME_FUNDO, CNPJ_CEDENTE, PERC_PARTICIPACAO,
               {cols_sql}, VL_TOTAL
        FROM cedentes_cronograma
        WHERE CNPJ_CEDENTE = :cnpj
        ORDER BY VL_TOTAL DESC
    """
    with engine.connect() as conn:
        rows = conn.execute(text(query), {"cnpj": cnpj}).mappings().all()
    return {"cnpj": cnpj, "colunas": year_cols, "fundos": [dict(r) for r in rows]}


@app.get("/resumo")
def resumo():
    """KPIs: total de cedentes, exposicao total, top 10."""
    with engine.connect() as conn:
        stats = conn.execute(text("""
            SELECT COUNT(DISTINCT CNPJ_CEDENTE) AS total_cedentes,
                   SUM(VL_TOTAL) AS total_exposicao
            FROM cedentes_cronograma
        """)).mappings().first()

        top10 = conn.execute(text("""
            SELECT CNPJ_CEDENTE,
                   SUM(VL_TOTAL) AS VL_TOTAL,
                   COUNT(DISTINCT CNPJ_FUNDO) AS NUM_FUNDOS
            FROM cedentes_cronograma
            GROUP BY CNPJ_CEDENTE
            ORDER BY VL_TOTAL DESC
            LIMIT 10
        """)).mappings().all()

    return {
        "total_cedentes": stats["total_cedentes"],
        "total_exposicao": float(stats["total_exposicao"] or 0),
        "top10": [dict(r) for r in top10],
    }
