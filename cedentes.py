import pandas as pd
from sqlalchemy import create_engine, text
import re
import os
from config import DB_URL

engine = create_engine(DB_URL)


def obter_mes_atual():
    """Lê o mês carregado pelo carga_dados.py (arquivo .mes_atual)."""
    meta_path = os.path.join(os.path.dirname(__file__), '.mes_atual')
    if os.path.exists(meta_path):
        with open(meta_path) as f:
            return f.read().strip()
    raise RuntimeError("Arquivo .mes_atual não encontrado. Execute carga_dados.py primeiro.")

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
    yyyymm = obter_mes_atual()
    print(f"--- PROCESSANDO DADOS DO MÊS {yyyymm} ---")

    try:
        df_i = pd.read_sql(f'SELECT * FROM `inf_mensal_fidc_tab_I_{yyyymm}`', engine)
        df_v = pd.read_sql(f'SELECT * FROM `inf_mensal_fidc_tab_V_{yyyymm}`', engine)
    except Exception as e:
        print(f"Erro ao ler tabelas: {e}")
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
                
                # Limpeza e filtro do CNPJ do cedente
                temp['CNPJ_CEDENTE'] = temp['CNPJ_CEDENTE'].apply(limpar_cnpj)
                temp = temp[temp['CNPJ_CEDENTE'] != '']
                if not temp.empty:
                    lista_cedentes.append(temp)

    # Evitamos o FutureWarning filtrando apenas listas que não estão vazias
    if not lista_cedentes:
        print("ERRO: Nenhum cedente encontrado nos dados.")
        return
        
    df_cedentes = pd.concat(lista_cedentes, ignore_index=True)
    
    # Limpando os valores financeiros para garantir que as contas matemáticas funcionem
    df_cedentes['TAB_I2_VL_CARTEIRA'] = df_cedentes['TAB_I2_VL_CARTEIRA'].apply(limpar_valor)
    df_cedentes['PERC_PARTICIPACAO'] = df_cedentes['PERC_PARTICIPACAO'].apply(limpar_valor)

    # 2. MAPEAMENTO DOS PRAZOS (Tabela V) - todos os 10 buckets
    # Buckets em dias e seus limites superiores
    buckets_dias = {
        1: 30, 2: 60, 3: 90, 4: 120, 5: 150,
        6: 180, 7: 360, 8: 720, 9: 1080, 10: 9999
    }
    mapeamento_venc = {}
    for i in range(1, 11):
        prefixo = f'TAB_V_A{i}'
        col_encontrada = [c for c in df_v.columns if c.startswith(prefixo)]
        if col_encontrada:
            mapeamento_venc[i] = col_encontrada[0]
            df_v[mapeamento_venc[i]] = df_v[mapeamento_venc[i]].apply(limpar_valor)

    # Garantir DT_COMPTC na Tab V para calcular ano de vencimento
    if 'DT_COMPTC' in df_v.columns:
        df_v['DT_COMPTC'] = pd.to_datetime(df_v['DT_COMPTC'], errors='coerce')

    cols_para_remover = [c for c in df_v.columns if c in df_cedentes.columns and c != 'CNPJ_FUNDO_CLASSE']
    df_v_limpo = df_v.drop(columns=cols_para_remover)

    # 3. MERGE
    df_final = pd.merge(df_cedentes, df_v_limpo, on='CNPJ_FUNDO_CLASSE', how='inner')
    if df_final.empty:
        print("ERRO: O cruzamento falhou.")
        return

    # 4. CALCULO PONDERADO por bucket de dias
    for i in range(1, 11):
        nome = f'VL_BUCKET_{i}'
        if i in mapeamento_venc:
            df_final[nome] = df_final[mapeamento_venc[i]] * (df_final['PERC_PARTICIPACAO'] / 100)
        else:
            df_final[nome] = 0.0

    # 5. AGRUPAR BUCKETS EM ANOS baseado na data de competencia
    from datetime import timedelta
    if 'DT_COMPTC' in df_final.columns:
        dt_ref = df_final['DT_COMPTC'].dropna().iloc[0] if df_final['DT_COMPTC'].notna().any() else pd.Timestamp(f'{yyyymm[:4]}-{yyyymm[4:]}-01')
    else:
        dt_ref = pd.Timestamp(f'{yyyymm[:4]}-{yyyymm[4:]}-01')
    ano_ref = dt_ref.year

    # Mapear cada bucket de dias para um ano
    ano_por_bucket = {}
    for i, dias in buckets_dias.items():
        if dias == 9999:
            ano_por_bucket[i] = ano_ref + 4  # >1080d = ano_ref+4 ou mais
        else:
            dt_venc = dt_ref + timedelta(days=dias)
            ano_por_bucket[i] = dt_venc.year

    # Gerar colunas anuais
    anos = sorted(set(ano_por_bucket.values()))
    for ano in anos:
        col_ano = f'VL_{ano}' if ano < ano_ref + 4 else f'VL_{ano}_OU_MAIOR'
        buckets_do_ano = [f'VL_BUCKET_{i}' for i, a in ano_por_bucket.items() if a == ano]
        df_final[col_ano] = df_final[buckets_do_ano].sum(axis=1)

    # Coluna de total
    cols_anos = [f'VL_{ano}' if ano < ano_ref + 4 else f'VL_{ano}_OU_MAIOR' for ano in anos]
    df_final['VL_TOTAL'] = df_final[cols_anos].sum(axis=1)

    # 6. TABELA LIMPA
    df_limpo = df_final[['CNPJ_FUNDO_CLASSE', 'DENOM_SOCIAL', 'CNPJ_CEDENTE',
                          'PERC_PARTICIPACAO'] + cols_anos + ['VL_TOTAL']].copy()

    # Dropar cedentes nao identificados
    df_limpo['CNPJ_CEDENTE'] = df_limpo['CNPJ_CEDENTE'].apply(limpar_cnpj)
    df_limpo = df_limpo[
        (df_limpo['CNPJ_CEDENTE'] != '') &
        (df_limpo['CNPJ_CEDENTE'] != '0') &
        (df_limpo['CNPJ_CEDENTE'] != '00000000000000') &
        (df_limpo['CNPJ_CEDENTE'].str.strip('0') != '') &
        (df_limpo['VL_TOTAL'] > 0)
    ]

    df_limpo = df_limpo.rename(columns={
        'CNPJ_FUNDO_CLASSE': 'CNPJ_FUNDO',
        'DENOM_SOCIAL': 'NOME_FUNDO',
    })

    # Salvar tabela limpa
    with engine.connect() as conn:
        conn.execute(text("DROP TABLE IF EXISTS `cedentes_cronograma`"))
        conn.commit()
    df_limpo.to_sql('cedentes_cronograma', engine, if_exists='append', index=False)

    print(f"SUCESSO! {len(df_limpo)} cedentes limpos.")
    print(f"Colunas anuais: {cols_anos}")
    print(df_limpo[['NOME_FUNDO', 'CNPJ_CEDENTE', 'VL_TOTAL']].head())

if __name__ == "__main__":
    processar_dados_cvm()