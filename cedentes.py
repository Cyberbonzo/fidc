import pandas as pd
from sqlalchemy import create_engine
import re
from config import DB_URL

engine = create_engine(DB_URL)

def limpar_valor(valor):
    """
    Trata números que vêm com pontos e vírgulas do CSV/Postgres.
    Exemplo: '1.200,50' vira 1200.50
    """
    if pd.isna(valor) or valor == "": return 0.0
    s = str(valor).strip()
    # Se tiver vírgula e ponto, remove o ponto (milhar) e troca a vírgula por ponto (decimal)
    if ',' in s:
        s = s.replace('.', '').replace(',', '.')
    try:
        return float(s)
    except ValueError:
        return 0.0

def limpar_cnpj(cnpj):
    """Remove qualquer caractere que não seja número (pontos, barras, etc)."""
    if pd.isna(cnpj): return ""
    return re.sub(r'\D', '', str(cnpj))

def processar_dados_cvm():
    print("--- 🔍 INICIANDO DIAGNÓSTICO DE DADOS ---")
    
    try:
        # Lendo as tabelas do MySQL
        df_i = pd.read_sql('SELECT * FROM `inf_mensal_fidc_tab_I_202602`', engine)
        df_v = pd.read_sql('SELECT * FROM `inf_mensal_fidc_tab_V_202602`', engine)
    except Exception as e:
        print(f"❌ Erro ao ler tabelas: {e}")
        return

    # PADRONIZAÇÃO: Forçamos todos os nomes de colunas para MAIÚSCULAS e removemos aspas
    # Isso evita que o script se perca se o banco salvar em minúsculo.
    df_i.columns = df_i.columns.str.strip().str.replace('"', '').str.upper()
    df_v.columns = df_v.columns.str.strip().str.replace('"', '').str.upper()

    # --- LIMPEZA DE CNPJ PARA O CRUZAMENTO ---
    df_i['CNPJ_FUNDO_CLASSE'] = df_i['CNPJ_FUNDO_CLASSE'].apply(limpar_cnpj)
    df_v['CNPJ_FUNDO_CLASSE'] = df_v['CNPJ_FUNDO_CLASSE'].apply(limpar_cnpj)

    # 1. MAPEAMENTO DE CEDENTES (Tabela I)
    cols_id = ['CNPJ_FUNDO_CLASSE', 'DENOM_SOCIAL', 'ADMIN', 'TAB_I2_VL_CARTEIRA']
    # Filtramos uma única vez quais colunas de identificação existem no DataFrame
    cols_presentes = [c for c in cols_id if c in df_i.columns]
    lista_cedentes = []

    # Procuramos os campos de cedentes (TAB_I2A12... ou TAB_I2B12...)
    for i in range(1, 15):
        for tipo in ['A', 'B']:
            col_cnpj = f'TAB_I2{tipo}12_CPF_CNPJ_CEDENTE_{i}'
            col_perc = f'TAB_I2{tipo}12_PR_CEDENTE_{i}'
            if col_cnpj in df_i.columns:
                temp = df_i[cols_presentes + [col_cnpj, col_perc]].copy()
                temp.columns = cols_presentes + ['CNPJ_CEDENTE', 'PERC_PARTICIPACAO']
                
                # Filtramos apenas linhas onde o CNPJ do cedente não é vazio
                temp = temp.dropna(subset=['CNPJ_CEDENTE'])
                if not temp.empty:
                    lista_cedentes.append(temp)

    # Evitamos o FutureWarning filtrando apenas listas que não estão vazias
    if not lista_cedentes:
        print("❌ Nenhum cedente encontrado nos dados.")
        return
        
    df_cedentes = pd.concat(lista_cedentes, ignore_index=True)
    
    # Limpando os valores financeiros para garantir que as contas matemáticas funcionem
    df_cedentes['TAB_I2_VL_CARTEIRA'] = df_cedentes['TAB_I2_VL_CARTEIRA'].apply(limpar_valor)
    df_cedentes['PERC_PARTICIPACAO'] = df_cedentes['PERC_PARTICIPACAO'].apply(limpar_valor)

    # 2. MAPEAMENTO DINÂMICO DOS PRAZOS (Tabela V)
    mapeamento_venc = {}
    for i in range(1, 9):
        prefixo = f'TAB_V_A{i}'
        # Procura qualquer coluna que comece com TAB_V_A1, TAB_V_A2...
        col_encontrada = [c for c in df_v.columns if c.startswith(prefixo)]
        if col_encontrada:
            mapeamento_venc[i] = col_encontrada[0]
            df_v[mapeamento_venc[i]] = df_v[mapeamento_venc[i]].apply(limpar_valor)
            print(f"✅ Coluna identificada para Prazo {i}: {mapeamento_venc[i]}")

    # --- AJUSTE PARA O MERGE ---
    # Para evitar colunas duplicadas (DENOM_SOCIAL_x, DENOM_SOCIAL_y),
    # removemos da Tabela V colunas que já existem na Tabela I (exceto o CNPJ do fundo)
    cols_para_remover = [c for c in df_v.columns if c in df_cedentes.columns and c != 'CNPJ_FUNDO_CLASSE']
    df_v_limpo = df_v.drop(columns=cols_para_remover)

    # 3. O CRUZAMENTO (MERGE)
    df_final = pd.merge(df_cedentes, df_v_limpo, on='CNPJ_FUNDO_CLASSE', how='inner')
    
    if df_final.empty:
        print("⚠️ ERRO: O cruzamento falhou. Verifique se os CNPJs dos fundos nas duas tabelas são iguais.")
        return

    # 4. CÁLCULO PONDERADO (VALOR DO PRAZO * PERCENTUAL DE PARTICIPAÇÃO)
    for i in range(1, 9):
        novo_nome = f'VALOR_CEDENTE_{i}'
        if i in mapeamento_venc:
            col_origem = mapeamento_venc[i]
            df_final[novo_nome] = df_final[col_origem] * (df_final['PERC_PARTICIPACAO'] / 100)
        else:
            df_final[novo_nome] = 0.0

    # 5. SALVANDO O RESULTADO NO MYSQL
    df_final.to_sql('cronograma_cedentes', engine, if_exists='replace', index=False)
    
    print(f"🚀 SUCESSO! {len(df_final)} registros processados.")
    
    # 6. PRÉVIA SEGURA: Verificamos se a coluna existe antes de imprimir
    print("\n--- PRÉVIA DOS CÁLCULOS (Primeiras 5 linhas) ---")
    cols_preview = ['DENOM_SOCIAL', 'VALOR_CEDENTE_1', 'PERC_PARTICIPACAO']
    cols_preview_existentes = [c for c in cols_preview if c in df_final.columns]
    print(df_final[cols_preview_existentes].head())

if __name__ == "__main__":
    processar_dados_cvm()