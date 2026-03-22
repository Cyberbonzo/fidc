import pandas as pd
from sqlalchemy import create_engine, text
import requests
import time
from config import DB_URL

engine = create_engine(DB_URL)

BRASIL_API = 'https://brasilapi.com.br/api/cnpj/v1'


def criar_tabela_cache():
    """Cria tabela de cache se nao existir."""
    with engine.connect() as conn:
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS cnpj_nomes (
                CNPJ VARCHAR(14) PRIMARY KEY,
                RAZAO_SOCIAL VARCHAR(255),
                NOME_FANTASIA VARCHAR(255)
            )
        """))
        conn.commit()


def buscar_nome(cnpj):
    """Consulta BrasilAPI para obter razao social."""
    try:
        r = requests.get(f'{BRASIL_API}/{cnpj}', timeout=10)
        if r.status_code == 200:
            data = r.json()
            return data.get('razao_social', ''), data.get('nome_fantasia', '')
        elif r.status_code == 429:
            print(f"  Rate limit atingido, aguardando 60s...")
            time.sleep(60)
            return buscar_nome(cnpj)  # retry
        else:
            return 'nao encontrado', ''
    except Exception as e:
        print(f"  Erro ao consultar {cnpj}: {e}")
        return 'nao encontrado', ''


def consultar_cedentes():
    """Busca nomes de todos os cedentes que ainda nao estao no cache."""
    criar_tabela_cache()

    # CNPJs unicos da tabela de cedentes
    df = pd.read_sql(text(
        "SELECT DISTINCT CNPJ_CEDENTE FROM cedentes_cronograma"
    ), engine)
    todos = set(df['CNPJ_CEDENTE'].tolist())

    # CNPJs ja consultados
    df_cache = pd.read_sql(text("SELECT CNPJ FROM cnpj_nomes"), engine)
    ja_tem = set(df_cache['CNPJ'].tolist())

    faltam = todos - ja_tem
    print(f"Total cedentes: {len(todos)} | Ja consultados: {len(ja_tem)} | Faltam: {len(faltam)}")

    if not faltam:
        print("Todos os CNPJs ja estao no cache.")
        return

    for i, cnpj in enumerate(sorted(faltam), 1):
        print(f"[{i}/{len(faltam)}] Consultando {cnpj}...", end='')
        razao, fantasia = buscar_nome(cnpj)
        nome = razao or fantasia or ''
        print(f" {nome[:50]}")

        with engine.connect() as conn:
            conn.execute(
                text("INSERT INTO cnpj_nomes (CNPJ, RAZAO_SOCIAL, NOME_FANTASIA) VALUES (:c, :r, :f)"),
                {"c": cnpj, "r": razao, "f": fantasia}
            )
            conn.commit()

        # Delay para nao sobrecarregar a API
        time.sleep(0.5)

    print(f"\nConsulta finalizada! {len(faltam)} novos nomes salvos.")


if __name__ == "__main__":
    consultar_cedentes()
