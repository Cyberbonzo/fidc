import pandas as pd
from sqlalchemy import create_engine, text
from datetime import datetime, timedelta
import os
import zipfile
import requests
from config import DB_URL

engine = create_engine(DB_URL)

BASE_URL = 'https://dados.cvm.gov.br/dados/FIDC/DOC/INF_MENSAL/DADOS'
PASTA_DADOS = os.path.join(os.path.dirname(__file__), 'dados')


def descobrir_mes_mais_recente():
    """Tenta encontrar o ZIP mais recente na CVM, começando pelo mês atual e voltando."""
    data = datetime.now()
    for _ in range(6):
        yyyymm = data.strftime('%Y%m')
        url = f'{BASE_URL}/inf_mensal_fidc_{yyyymm}.zip'
        resp = requests.head(url, timeout=10)
        if resp.status_code == 200:
            print(f"Mês mais recente disponível: {yyyymm}")
            return yyyymm, url
        data = (data.replace(day=1) - timedelta(days=1))
    raise RuntimeError("Nenhum arquivo encontrado nos últimos 6 meses na CVM.")


def baixar_e_extrair(url, yyyymm):
    """Baixa o ZIP da CVM e extrai os CSVs na pasta dados/."""
    os.makedirs(PASTA_DADOS, exist_ok=True)
    zip_path = os.path.join(PASTA_DADOS, f'inf_mensal_fidc_{yyyymm}.zip')

    print(f"Baixando {url} ...")
    resp = requests.get(url, timeout=120)
    resp.raise_for_status()
    with open(zip_path, 'wb') as f:
        f.write(resp.content)
    print(f"Download concluído ({len(resp.content) / 1024 / 1024:.1f} MB)")

    with zipfile.ZipFile(zip_path, 'r') as zf:
        zf.extractall(PASTA_DADOS)
        print(f"Extraídos: {zf.namelist()}")

    return yyyymm


def carregar_arquivos_cvm(yyyymm=None):
    """Baixa o ZIP mais recente da CVM e carrega Tab I e Tab V no MySQL."""
    if yyyymm is None:
        yyyymm, url = descobrir_mes_mais_recente()
        baixar_e_extrair(url, yyyymm)

    arquivos = {
        f'inf_mensal_fidc_tab_I_{yyyymm}.csv': f'inf_mensal_fidc_tab_I_{yyyymm}',
        f'inf_mensal_fidc_tab_V_{yyyymm}.csv': f'inf_mensal_fidc_tab_V_{yyyymm}',
    }

    print(f"\n--- INICIANDO CARGA DE DADOS ({yyyymm}) ---")

    for nome_arquivo, nome_tabela in arquivos.items():
        caminho = os.path.join(PASTA_DADOS, nome_arquivo)
        try:
            print(f"Lendo: {nome_arquivo}...")
            df = pd.read_csv(caminho, sep=';', encoding='latin1', low_memory=False)
            df.columns = df.columns.str.strip().str.replace('"', '').str.upper()

            print(f"Enviando {len(df)} linhas para '{nome_tabela}'...")
            with engine.connect() as conn:
                conn.execute(text(f"DROP TABLE IF EXISTS `{nome_tabela}`"))
                conn.commit()
            df.to_sql(nome_tabela, engine, if_exists='append', index=False)
            print(f"OK: {nome_tabela}\n")

        except Exception as e:
            print(f"ERRO ao processar {nome_arquivo}: {e}\n")

    # Salva o mês carregado para que cedentes.py saiba qual tabela usar
    meta_path = os.path.join(os.path.dirname(__file__), '.mes_atual')
    with open(meta_path, 'w') as f:
        f.write(yyyymm)

    print(f"--- CARGA CONCLUÍDA ({yyyymm}) ---")


if __name__ == "__main__":
    carregar_arquivos_cvm()
