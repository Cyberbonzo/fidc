import pandas as pd
from sqlalchemy import create_engine
import os
from config import DB_URL

engine = create_engine(DB_URL)

def carregar_arquivos_cvm():
    # Defina aqui os nomes EXATOS dos arquivos que estão na pasta 'dados/'
    # Se o mês mudar, altere o final do nome (ex: 202601)
    PASTA_DADOS = os.path.join(os.path.dirname(__file__), 'dados')

    arquivos = {
        'inf_mensal_fidc_tab_I_202602.csv': 'inf_mensal_fidc_tab_I_202602',
        'inf_mensal_fidc_tab_V_202602.csv': 'inf_mensal_fidc_tab_V_202602'
    }

    print("--- 🚀 INICIANDO CARGA DE DADOS PARA O MYSQL (XAMPP) ---")

    for nome_arquivo, nome_tabela in arquivos.items():
        caminho = os.path.join(PASTA_DADOS, nome_arquivo)
        try:
            print(f"⏳ Lendo e limpando: {nome_arquivo}...")
            # sep=';' e encoding='latin1' são cruciais para dados da CVM (Brasil)
            df = pd.read_csv(caminho, sep=';', encoding='latin1', low_memory=False)

            # Padroniza colunas para evitar erro de aspas/espaços no SQL
            df.columns = df.columns.str.strip().str.replace('"', '').str.upper()

            print(f"📤 Enviando {len(df)} linhas para a tabela '{nome_tabela}'...")
            df.to_sql(nome_tabela, engine, if_exists='replace', index=False)
            print(f"✅ Sucesso!\n")

        except Exception as e:
            print(f"❌ Erro ao processar {nome_arquivo}: {e}\n")

    print("--- 🏆 CARGA CONCLUÍDA ---")

if __name__ == "__main__":
    carregar_arquivos_cvm()