<?php
/**
 * ============================================================
 *  PAINEL DE MONITORAMENTO DE CRÉDITO FIDC
 * ============================================================
 *
 * O QUE É UM FIDC?
 * ----------------
 * FIDC = Fundo de Investimento em Direitos Creditórios.
 * É um tipo de fundo regulado pela CVM (Comissão de Valores
 * Mobiliários) que compra recebíveis de empresas (os "cedentes").
 * Exemplo: uma loja vende seus boletos a prazo para o fundo,
 * que antecipa o dinheiro e recebe os pagamentos futuramente.
 *
 * O QUE ESTE ARQUIVO FAZ?
 * -----------------------
 * Este arquivo PHP é a camada de apresentação (View) do sistema.
 * Ele faz três coisas em sequência:
 *   1. Conecta ao banco MySQL e busca os dados processados pelo Python
 *   2. Calcula os indicadores de resumo (KPIs) em PHP
 *   3. Renderiza o HTML do dashboard com os dados interpolados
 *
 * FLUXO COMPLETO DO SISTEMA:
 * --------------------------
 *   Arquivos CSV da CVM
 *       ↓
 *   carga_dados.py  → carrega os CSVs brutos no MySQL
 *       ↓
 *   cedentes.py     → cruza tabelas e calcula exposição por cedente
 *       ↓
 *   index.php       → exibe o resultado no navegador (este arquivo)
 *
 * ESPECIFICAÇÕES TÉCNICAS:
 * - Linguagem Backend : PHP 8.x
 * - Estilo Frontend   : Tailwind CSS (Azul Institucional)
 * - Banco de Dados    : MySQL (via XAMPP)
 * - Unidade Financeira: Milhares (valores ÷ 1.000 na exibição)
 */


/* ============================================================
 * BLOCO 1 — CONFIGURAÇÃO DA CONEXÃO COM O BANCO DE DADOS
 * ============================================================
 *
 * Aqui definimos as variáveis que identificam onde está o banco
 * e como se autenticar nele. No XAMPP, o MySQL roda localmente
 * na porta 3306, com usuário 'root' e sem senha por padrão.
 *
 * BOAS PRÁTICAS EM PRODUÇÃO:
 * Nunca deixe credenciais hardcoded em código que vai para
 * repositórios públicos. O ideal é usar variáveis de ambiente
 * (ex: $_ENV['DB_PASSWORD']) lidas de um arquivo .env que fica
 * fora do controle de versão (no .gitignore).
 */
$host     = '127.0.0.1'; // Endereço do servidor MySQL. Usamos o IP explícito para
                          // evitar ambiguidade com o socket Unix do 'localhost'.
$port     = '3306';       // Porta padrão do MySQL (diferente do PostgreSQL que usa 5432).
$dbname   = 'fidc';       // Nome do banco criado manualmente no phpMyAdmin.
$user     = 'root';       // Usuário padrão do XAMPP.
$password = '';           // Senha vazia é o padrão do XAMPP em ambiente local.


/* ============================================================
 * BLOCO 2 — CONEXÃO PDO E CONSULTA AO BANCO
 * ============================================================
 *
 * O QUE É O PDO?
 * PDO = PHP Data Objects. É uma interface abstrata do PHP para
 * acessar bancos de dados. A vantagem do PDO sobre funções
 * antigas (como mysql_query) é que:
 *   - Funciona com vários bancos (MySQL, PostgreSQL, SQLite...)
 *   - Suporta Prepared Statements nativamente (proteção contra SQL Injection)
 *   - Tem tratamento de erros via exceções (try/catch)
 *
 * O QUE É TRY/CATCH?
 * É uma estrutura de tratamento de erros. O código dentro do
 * bloco "try" é executado normalmente. Se qualquer linha lançar
 * uma exceção (um erro grave), a execução pula imediatamente
 * para o bloco "catch", evitando que a página quebre sem aviso.
 */
try {

    /* ----------------------------------------------------------
     * DSN (Data Source Name)
     * ----------------------------------------------------------
     * O DSN é uma string padronizada que o PDO usa para saber:
     *   - Qual driver usar         → mysql:
     *   - Onde está o servidor     → host=127.0.0.1
     *   - Em qual porta            → port=3306
     *   - Qual banco de dados      → dbname=fidc
     *   - Qual codificação de texto→ charset=utf8mb4
     *
     * utf8mb4 é a codificação recomendada para MySQL pois suporta
     * todos os caracteres Unicode, incluindo emojis e acentos
     * do português. O "mb4" significa "multi-byte 4 bytes".
     */
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    /* ----------------------------------------------------------
     * Instanciando o objeto PDO
     * ----------------------------------------------------------
     * O terceiro argumento é um array de opções (atributos):
     *
     * PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
     *   Configura o PDO para lançar exceções (PDOException) em
     *   caso de erro. Sem isso, erros silenciosos passariam
     *   despercebidos. É a configuração recomendada sempre.
     *
     * PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
     *   Define que fetchAll() retorna arrays associativos:
     *   $row['DENOM_SOCIAL'] em vez de $row[0].
     *   Muito mais legível e seguro para manutenção do código.
     */
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);


    /* ----------------------------------------------------------
     * BLOCO 2.1 — CAPTURA DO PARÂMETRO DE BUSCA DA URL
     * ----------------------------------------------------------
     * Quando o usuário digita algo na barra de pesquisa e clica
     * em "Pesquisar", o formulário envia uma requisição GET:
     *   http://localhost/fidc/?busca=PETROBRAS
     *
     * $_GET é um array superglobal do PHP que contém todos os
     * parâmetros passados na URL. Acessamos $_GET['busca'] para
     * recuperar o termo digitado.
     *
     * isset() verifica se a chave 'busca' existe no array $_GET
     * antes de acessá-la, evitando avisos de "undefined index".
     * Se não existir (primeira visita à página), $busca = ''.
     *
     * OPERADOR TERNÁRIO: condição ? valor_se_true : valor_se_false
     * É uma forma compacta do if/else para atribuição de variável.
     */
    $busca = isset($_GET['busca']) ? $_GET['busca'] : '';


    /* ----------------------------------------------------------
     * BLOCO 2.2 — MONTAGEM E EXECUÇÃO DA QUERY SQL
     * ----------------------------------------------------------
     * Temos dois caminhos:
     *   A) Com busca → filtra por nome do fundo ou CNPJ do cedente
     *   B) Sem busca → traz todos os registros
     *
     * Em ambos os casos, ordenamos por PERC_PARTICIPACAO DESC
     * para que os cedentes com maior concentração apareçam
     * primeiro — priorizando visibilidade de risco.
     */
    if (!empty($busca)) {

        /* ......................................................
         * PREPARED STATEMENTS (Declarações Preparadas)
         * ......................................................
         * São a forma correta e segura de passar parâmetros
         * variáveis para uma query SQL.
         *
         * SEM Prepared Statement (PERIGOSO - SQL Injection):
         *   $query = "SELECT * FROM tabela WHERE nome = '$busca'";
         *   Se $busca for: ' OR '1'='1
         *   A query vira: WHERE nome = '' OR '1'='1'
         *   → Retorna TODOS os registros! Brecha de segurança grave.
         *
         * COM Prepared Statement (SEGURO):
         *   O placeholder :busca é substituído de forma segura
         *   pelo PDO, que escapa os caracteres especiais
         *   automaticamente antes de enviar ao banco.
         *
         * LIKE com % (curingas):
         *   "%texto%" significa "qualquer coisa ANTES e DEPOIS
         *   do texto". Assim, buscar "petro" encontra "PETROBRAS",
         *   "PETROQUIMICA", etc. O % é adicionado na hora do
         *   execute(), não dentro da query.
         *
         * LIKE no MySQL é case-insensitive por padrão (diferente
         * do PostgreSQL que exige ILIKE para ignorar maiúsculas).
         */
        $query = 'SELECT * FROM `cronograma_cedentes`
                  WHERE `DENOM_SOCIAL` LIKE :busca
                  OR `CNPJ_CEDENTE` LIKE :busca
                  ORDER BY `PERC_PARTICIPACAO` DESC';

        // prepare() analisa e compila a query no banco de dados,
        // retornando um objeto PDOStatement ($stmt).
        $stmt = $pdo->prepare($query);

        // execute() substitui o placeholder :busca pelo valor real,
        // com os % como curingas de "contém".
        $stmt->execute(['busca' => "%$busca%"]);

    } else {

        // query() é um atalho do PDO para executar queries simples
        // sem parâmetros variáveis. Não há risco de injection aqui
        // pois não há entrada do usuário na string SQL.
        $query = 'SELECT * FROM `cronograma_cedentes` ORDER BY `PERC_PARTICIPACAO` DESC';
        $stmt  = $pdo->query($query);
    }

    /* ----------------------------------------------------------
     * fetchAll() — Trazendo todos os resultados para a memória
     * ----------------------------------------------------------
     * fetchAll() executa a query e retorna TODAS as linhas de
     * uma vez como um array PHP. Cada elemento do array é uma
     * linha da tabela, no formato ['coluna' => 'valor', ...].
     *
     * ATENÇÃO: Para tabelas muito grandes (centenas de milhares
     * de linhas), fetchAll() pode consumir muita memória RAM.
     * Nesse caso, o ideal seria usar fetch() em um loop ou
     * implementar paginação. Para o volume atual do projeto,
     * fetchAll() é adequado e mais simples.
     */
    $dados = $stmt->fetchAll();


    /* ============================================================
     * BLOCO 3 — CÁLCULO DOS KPIs (Key Performance Indicators)
     * ============================================================
     *
     * Antes de renderizar o HTML, calculamos os valores de resumo
     * que aparecerão nos cards do topo da página. Isso é feito em
     * PHP, iterando sobre os dados já carregados em $dados.
     *
     * Por que calcular em PHP e não no SQL?
     * Poderíamos usar SUM() no SQL, mas ao iterar sobre $dados
     * aqui aproveitamos os mesmos dados já carregados sem fazer
     * uma segunda consulta ao banco — mais eficiente.
     */

    // Acumulador para o valor total da carteira de todos os fundos.
    $totalCarteira = 0;

    /* array_fill(início, quantidade, valor_inicial)
     * Cria um array com 8 posições (índices 1 a 8) todas zeradas.
     * Cada posição representa um "balde" de vencimento:
     *   [1] → até 30 dias
     *   [2] → até 60 dias
     *   [3] → até 90 dias
     *   ... e assim por diante até 720 dias
     * Usamos índice 1 (não 0) para alinhar com os nomes dos campos
     * VALOR_CEDENTE_1, VALOR_CEDENTE_2... gerados pelo Python.
     */
    $vencimentosTotais = array_fill(1, 8, 0);

    // Contador de cedentes com concentração acima do limite de risco.
    $alertasCount = 0;

    /* foreach itera sobre cada linha da tabela retornada pelo banco.
     * $row é um array associativo com os valores de uma linha.
     * Exemplo: $row['DENOM_SOCIAL'] = 'FIDC EXEMPLO'
     */
    foreach ($dados as $row) {

        /* (float) é um type cast: converte o valor para número decimal.
         * É necessário porque o PDO retorna todos os valores como strings.
         * Sem o cast, a soma $totalCarteira += "1000" funcionaria em PHP
         * (por coerção de tipo), mas é boa prática ser explícito.
         *
         * ?? é o operador "null coalescing": retorna o valor à esquerda
         * se ele existir e não for null; caso contrário, retorna o valor
         * à direita (0). Evita erros caso a coluna não exista na linha.
         */
        $totalCarteira += (float) ($row['TAB_I2_VL_CARTEIRA'] ?? 0);

        // Loop de 1 a 8 para somar cada balde de vencimento.
        // "VALOR_CEDENTE_$i" usa interpolação de variável dentro de string
        // com aspas duplas, gerando: VALOR_CEDENTE_1, VALOR_CEDENTE_2, etc.
        for ($i = 1; $i <= 8; $i++) {
            $vencimentosTotais[$i] += (float) ($row["VALOR_CEDENTE_$i"] ?? 0);
        }

        // Regra de negócio: concentração > 20% em um único cedente é
        // considerada risco de crédito elevado pela regulação da CVM.
        // Contamos quantos cedentes estão nessa situação.
        if ((float) ($row['PERC_PARTICIPACAO'] ?? 0) > 20) {
            $alertasCount++;
        }
    }

    /* ----------------------------------------------------------
     * Mapeamento de rótulos dos prazos
     * ----------------------------------------------------------
     * Array associativo que traduz o índice numérico (1 a 8) para
     * o rótulo legível exibido na tela. O índice corresponde ao
     * número do "balde" gerado pelo script Python (cedentes.py).
     */
    $labels_prazos = [
        1 => '30d',
        2 => '60d',
        3 => '90d',
        4 => '120d',
        5 => '150d',
        6 => '180d',
        7 => '360d',
        8 => '720d'
    ];


/* ============================================================
 * BLOCO 4 — TRATAMENTO DE ERROS DE BANCO DE DADOS
 * ============================================================
 *
 * Se qualquer linha dentro do bloco "try" lançar uma PDOException
 * (ex: banco offline, senha errada, tabela inexistente), a execução
 * cai aqui no "catch".
 *
 * die() interrompe imediatamente a execução do script PHP e exibe
 * a string passada como argumento. É adequado para erros fatais
 * onde não faz sentido continuar renderizando a página.
 *
 * $e->getMessage() retorna a descrição técnica do erro gerada pelo
 * PDO/MySQL, útil para diagnóstico em desenvolvimento.
 *
 * NOTA: Em produção, evite exibir mensagens de erro técnicas para
 * o usuário final pois podem expor detalhes da infraestrutura.
 * O ideal é logar o erro em arquivo e exibir uma mensagem genérica.
 */
} catch (PDOException $e) {
    die("<div style='color:#1e3a8a; font-family:sans-serif; padding:50px; text-align:center;'>
            <h2 style='margin-bottom:10px;'>Erro de Conexão com o Banco</h2>
            <p>{$e->getMessage()}</p>
         </div>");
}
?>

<?php
/* ============================================================
 * SEPARAÇÃO ENTRE PHP E HTML
 * ============================================================
 * A tag ?> fecha o bloco PHP. Tudo que vem após é HTML puro,
 * que o servidor web envia diretamente para o navegador.
 *
 * PHP pode ser reaberto a qualquer momento com <?php ou <?=
 * para interpolar variáveis ou executar lógica dentro do HTML.
 *
 * <?= é um atalho para <?php echo. Usado para imprimir valores
 * diretamente no HTML. Exemplo: <?= $nome ?> imprime o valor
 * da variável $nome no ponto exato onde aparece no HTML.
 */
?>

<!DOCTYPE html>
<!--
    DOCTYPE html declara ao navegador que este é um documento HTML5.
    Sem essa declaração, navegadores antigos entram em "quirks mode"
    e podem renderizar o layout de forma incorreta.
-->
<html lang="pt-BR">
<!--
    lang="pt-BR" informa ao navegador e a mecanismos de busca que
    o conteúdo está em Português do Brasil. Importante para
    acessibilidade (leitores de tela) e SEO.
-->

<head>
    <!--
        A seção <head> contém metadados da página — informações
        sobre o documento que NÃO são exibidas diretamente ao usuário,
        mas que configuram o comportamento da página no navegador.
    -->

    <!-- Define a codificação de caracteres do HTML.
         UTF-8 suporta todos os caracteres do português (ã, ç, é...).
         Deve ser a primeira tag do <head> para funcionar corretamente. -->
    <meta charset="UTF-8">

    <!-- Viewport: instrui o navegador mobile a não fazer zoom automático.
         width=device-width → a largura da página = largura da tela
         initial-scale=1.0 → sem zoom inicial
         Essencial para que o layout responsivo (Tailwind) funcione
         corretamente em celulares e tablets. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Título exibido na aba do navegador e nos resultados de busca. -->
    <title>Dashboard FIDC</title>

    <!-- =========================================================
         TAILWIND CSS — Framework de Estilização via CDN
         =========================================================
         Tailwind é um framework CSS "utility-first": em vez de
         classes semânticas como .botao-azul, ele oferece classes
         atômicas como bg-blue-800, text-white, px-4, etc.

         Cada classe aplica exatamente uma propriedade CSS.
         Isso permite construir interfaces sem sair do HTML.

         CDN (Content Delivery Network): o arquivo do Tailwind é
         carregado diretamente da internet (não instalado localmente).
         Ótimo para desenvolvimento, mas em produção o ideal é
         usar o Tailwind CLI para gerar apenas as classes usadas
         (bundle otimizado, sem depender de CDN externo).

         Como funciona o script do Tailwind CDN:
         Ele lê todos os atributos class= do HTML em tempo real
         e gera o CSS correspondente dinamicamente no navegador. -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- =========================================================
         GOOGLE FONTS — Fonte Public Sans
         =========================================================
         Carrega a fonte tipográfica "Public Sans" do Google Fonts
         via CDN. Os pesos carregados são: 300 (light), 400 (regular),
         600 (semibold) e 800 (extrabold).

         A fonte é aplicada globalmente no CSS abaixo via
         font-family: 'Public Sans', sans-serif;
         O "sans-serif" é o fallback caso a fonte não carregue. -->
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;600;800&display=swap"
        rel="stylesheet">

    <style>
        /* Aplica a fonte Public Sans em todo o corpo da página.
           Esta regra sobrescreve a fonte padrão do navegador. */
        body {
            font-family: 'Public Sans', sans-serif;
        }

        /* =========================================================
           ESTILIZAÇÃO DA BARRA DE ROLAGEM HORIZONTAL DA TABELA
           =========================================================
           O seletor ::-webkit-scrollbar é uma extensão CSS não-padrão
           suportada pelo Chrome, Edge e Safari (navegadores Webkit).
           Permite customizar aparência da barra de rolagem.

           .scroll-custom::-webkit-scrollbar
             → seleciona A BARRA de rolagem do elemento com
               a classe "scroll-custom" (o container da tabela)
             → height: 8px define a espessura da barra horizontal

           .scroll-custom::-webkit-scrollbar-thumb
             → seleciona O POLEGAR (a parte que se arrasta)
             → background: #bfdbfe → cor azul clara (blue-200 do Tailwind)
             → border-radius: 10px → bordas arredondadas
        */
        .scroll-custom::-webkit-scrollbar {
            height: 8px;
        }

        .scroll-custom::-webkit-scrollbar-thumb {
            background: #bfdbfe;
            border-radius: 10px;
        }
    </style>
</head>

<!--
    CLASSES DO <body> (Tailwind):
    bg-[#f0f4f8]     → cor de fundo cinza-azulado personalizada (sintaxe de valor arbitrário)
    min-h-screen     → altura mínima = 100% da altura da tela (evita fundo cortado em páginas curtas)
    text-slate-900   → cor de texto padrão quase-preto
    overflow-x-hidden → esconde scroll horizontal no body (a tabela tem seu próprio scroll)
-->
<body class="bg-[#f0f4f8] min-h-screen text-slate-900 overflow-x-hidden">

    <!--
        CONTAINER PRINCIPAL
        max-w-full  → sem largura máxima, ocupa 100% da tela
        px-6 py-6   → padding horizontal e vertical de 1.5rem (24px)
    -->
    <div class="max-w-full px-6 py-6">

        <!-- ===========================================================
             SEÇÃO 1 — CABEÇALHO (HEADER)
             ===========================================================
             O <header> é um elemento semântico HTML5 que representa
             o cabeçalho da página. Semântica ajuda leitores de tela
             e mecanismos de busca a entender a estrutura do conteúdo.

             CLASSES TAILWIND EXPLICADAS:
             flex             → ativa Flexbox: filhos ficam em linha por padrão
             flex-col         → em telas pequenas, empilha os filhos verticalmente
             lg:flex-row      → em telas grandes (≥1024px), coloca em linha
             justify-between  → empurra o primeiro filho para esquerda e o último para direita
             items-center     → alinha verticalmente ao centro
             gap-6            → espaçamento de 1.5rem entre os filhos
             mb-8             → margin-bottom de 2rem (espaço abaixo do header)
             bg-white         → fundo branco
             p-6              → padding interno de 1.5rem
             rounded-2xl      → bordas bem arredondadas (border-radius: 1rem)
             shadow-sm        → sombra suave
             border border-blue-100 → borda fina em azul claríssimo
        -->
        <header
            class="flex flex-col lg:flex-row justify-between items-center gap-6 mb-8 bg-white p-6 rounded-2xl shadow-sm border border-blue-100">

            <!-- Grupo: Ícone + Título -->
            <div class="flex items-center gap-4">

                <!-- Quadrado azul com ícone SVG de gráfico -->
                <div class="bg-blue-800 p-4 rounded-xl shadow-lg">
                    <!--
                        SVG (Scalable Vector Graphics) — gráfico vetorial embutido.
                        Diferente de imagens raster (PNG, JPG), SVGs são descritos
                        por coordenadas matemáticas e não perdem qualidade em
                        qualquer tamanho. O ícone abaixo representa um gráfico
                        de linha ascendente, simbolizando monitoramento financeiro.
                        viewBox="0 0 24 24" → sistema de coordenadas 24x24
                        stroke="currentColor" → usa a cor de texto atual (white)
                    -->
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-white" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                    </svg>
                </div>

                <!-- Título e subtítulo -->
                <div class="text-center">
                    <h1 class="text-2xl font-black text-blue-900 tracking-tighter uppercase italic">Cronograma de Amortização - <span
                            class="text-blue-600">Debêntures</span></h1>
                    <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">em R$ mil</p>
                </div>
            </div>

            <!-- ===========================================================
                 FORMULÁRIO DE BUSCA
                 ===========================================================
                 method="GET" → os dados do formulário são enviados como
                 parâmetros na URL (?busca=...) em vez de no corpo da
                 requisição (POST). Para buscas/filtros, GET é preferível
                 pois o resultado é "bookmarkável" e o botão voltar funciona.

                 O PHP lê esses parâmetros via $_GET['busca'] no topo do arquivo.
            -->
            <form method="GET" class="flex gap-2 w-full lg:w-auto">

                <!--
                    INPUT DE TEXTO
                    type="text"  → campo de texto simples
                    name="busca" → nome do parâmetro enviado na URL
                    value="<?= htmlspecialchars($busca) ?>"
                      → pré-preenche o campo com o termo atual da busca,
                        mantendo o texto após o envio do formulário.
                      → htmlspecialchars() converte caracteres especiais HTML
                        (<, >, ", &) para entidades HTML seguras (&lt; &gt; etc.)
                        Isso evita XSS (Cross-Site Scripting): se alguém tentar
                        injetar <script>alert('hack')</script> no campo,
                        será exibido como texto, não executado como código.
                -->
                <input type="text" name="busca" placeholder="Filtrar por fundo ou CNPJ..."
                    value="<?= htmlspecialchars($busca) ?>"
                    class="px-5 py-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none text-sm w-full lg:w-[450px] shadow-sm transition-all">

                <!-- Botão de envio do formulário -->
                <button type="submit"
                    class="bg-blue-800 text-white px-8 py-3 rounded-xl font-bold text-sm hover:bg-blue-900 transition-all shadow-md">Pesquisar</button>

                <!--
                    Botão "Limpar" — só aparece quando há uma busca ativa.
                    A tag [php if ($busca):] é o if do PHP embutido no HTML.
                    O link aponta para index.php sem parâmetros,
                    efetivamente limpando o filtro ao recarregar a página.
                -->
                <?php if ($busca): ?>
                    <a href="index.php"
                        class="bg-slate-100 text-slate-500 px-5 py-3 rounded-xl font-bold text-sm flex items-center hover:bg-slate-200">Limpar</a>
                <?php endif; ?>
            </form>
        </header>


        <!-- ===========================================================
             SEÇÃO 2 — CARDS DE KPIs (Indicadores-chave de desempenho)
             ===========================================================
             KPI = Key Performance Indicator. São os números de resumo
             que permitem uma leitura rápida do estado geral da carteira.

             GRID RESPONSIVO DO TAILWIND:
             grid                → ativa CSS Grid Layout
             grid-cols-2         → 2 colunas em telas pequenas (mobile)
             md:grid-cols-5      → 5 colunas em telas médias (≥768px, tablets)
             lg:grid-cols-10     → 10 colunas em telas grandes (≥1024px, desktop)
             gap-3               → espaçamento de 0.75rem entre os cards
             mb-8                → margem inferior de 2rem

             Temos 10 cards: 1 (carteira total) + 8 (vencimentos) + 1 (alertas)
        -->
        <div class="grid grid-cols-2 md:grid-cols-5 lg:grid-cols-10 gap-3 mb-8">

            <!-- -------------------------------------------------------
                 Card 1: Carteira Total Consolidada
                 Fundo azul escuro para destacar o indicador principal.
            ------------------------------------------------------- -->
            <div class="bg-blue-900 text-white p-5 rounded-2xl shadow-xl flex flex-col justify-between">
                <span class="text-[8px] font-bold text-blue-300 uppercase tracking-widest text-center">Carteira
                    Total</span>
                <div class="text-lg font-black mt-2 text-center tracking-tighter">
                    <!--
                        number_format(número, decimais, separador_decimal, separador_milhar)
                        Formata o número para exibição em estilo brasileiro:
                        - Divide por 1000 → converte de reais para milhares
                        - 0 decimais
                        - ',' como separador decimal (padrão BR)
                        - '.' como separador de milhar (padrão BR)
                        Exemplo: 1234567 → "1.234" (em milhares)
                    -->
                    <?= number_format($totalCarteira / 1000, 0, ',', '.') ?>
                </div>
            </div>

            <!-- -------------------------------------------------------
                 Cards 2 a 9: Vencimentos por Prazo (30d a 720d)
                 Gerados dinamicamente pelo foreach do PHP.
            ------------------------------------------------------- -->
            <?php foreach ($vencimentosTotais as $idx => $valor): ?>
                <!--
                    foreach em PHP funciona como o "for item in lista" do Python.
                    $idx  → chave do array (1 a 8)
                    $valor → valor acumulado naquele balde de vencimento

                    A sintaxe foreach(...): ... endforeach; é equivalente
                    ao foreach com chaves {}, mas preferida dentro do HTML
                    pois deixa claro onde o loop começa e termina sem
                    precisar contar as chaves.
                -->
                <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 border-t-2 border-t-blue-500">
                    <span class="text-[8px] font-bold text-slate-400 uppercase tracking-widest text-center block">Venc.
                        <!-- $labels_prazos[$idx] acessa o rótulo correspondente:
                             $idx=1 → '30d', $idx=2 → '60d', etc. -->
                        <?= $labels_prazos[$idx] ?></span>
                    <div class="text-md font-bold text-blue-800 italic text-center mt-2">
                        <?= number_format($valor / 1000, 0, ',', '.') ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- -------------------------------------------------------
                 Card 10: Alertas de Concentração de Risco
                 Borda e texto vermelhos para sinalizar atenção.
                 O ponto pulsante (animate-pulse) reforça a urgência visual.
            ------------------------------------------------------- -->
            <div
                class="bg-white p-5 rounded-2xl shadow-sm border border-red-200 bg-red-50/30 flex flex-col justify-between">
                <span class="text-[8px] font-bold text-red-500 uppercase tracking-widest text-center">Risco > 20%</span>
                <div class="text-lg font-bold mt-2 text-red-600 flex items-center justify-center gap-2">
                    <!-- Número de cedentes com participação acima de 20% -->
                    <?= $alertasCount ?>
                    <!-- Ponto vermelho com animação de pulso (CSS do Tailwind) -->
                    <span class="w-2 h-2 bg-red-500 rounded-full animate-pulse"></span>
                </div>
            </div>
        </div>


        <!-- ===========================================================
             SEÇÃO 3 — TABELA DETALHADA DE EXPOSIÇÃO POR CEDENTE
             ===========================================================
             Esta é a seção principal do dashboard. Exibe todos os
             cedentes com seus respectivos valores de exposição por
             prazo de vencimento.

             ESTRUTURA DE UMA TABELA HTML:
             <table>   → elemento raiz da tabela
               <thead> → cabeçalho (linha de títulos das colunas)
               <tbody> → corpo (linhas de dados)
                 <tr>  → table row (linha)
                   <th> → table header (célula de cabeçalho, negrito por padrão)
                   <td> → table data (célula de dados)
        -->
        <div class="bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden">

            <!-- Barra superior da tabela: título + contador de registros -->
            <div class="p-6 bg-blue-50/50 border-b border-blue-100 flex justify-between items-center">
                <h3 class="text-sm font-black text-blue-900 uppercase tracking-tighter italic flex items-center gap-2">
                    <!-- Ícone SVG de tabela/relatório -->
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-800" viewBox="0 0 20 20"
                        fill="currentColor">
                        <path fill-rule="evenodd"
                            d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11 4a1 1 0 10-2 0v4a1 1 0 102 0V7zm-3 2a1 1 0 10-2 0v2a1 1 0 102 0V9z"
                            clip-rule="evenodd" />
                    </svg>
                    Mapa Detalhado de Exposição por Cedente
                </h3>
                <span
                    class="text-[10px] font-extrabold text-blue-800 bg-white px-4 py-1.5 border border-blue-100 rounded-lg shadow-sm">
                    <!--
                        count($dados) retorna quantos elementos há no array $dados,
                        ou seja, quantas linhas a query retornou do banco.
                    -->
                    <?= count($dados) ?> Registros Analisados
                </span>
            </div>

            <!--
                overflow-x-auto  → ativa barra de rolagem horizontal quando
                                   a tabela for mais larga que o container.
                scroll-custom    → aplica o estilo de scrollbar definido no <style> acima.
            -->
            <div class="overflow-x-auto scroll-custom">
                <!--
                    w-full          → largura total do container
                    border-collapse → remove o espaço duplo entre células adjacentes
                    whitespace-nowrap → impede quebra de linha nas células,
                                       mantendo cada coluna numa largura consistente
                -->
                <table class="w-full text-left border-collapse whitespace-nowrap">

                    <!-- CABEÇALHO DA TABELA -->
                    <thead>
                        <tr
                            class="bg-white text-slate-400 text-[9px] uppercase font-black tracking-[0.2em] border-b border-slate-100">

                            <!--
                                COLUNA FIXA (STICKY):
                                sticky left-0 → fixa esta coluna na borda esquerda
                                                durante o scroll horizontal.
                                bg-white       → fundo branco (necessário para cobrir
                                                 as colunas que passam por baixo).
                                z-10           → z-index alto para ficar acima das demais
                                                 colunas durante o scroll.
                                shadow-sm      → sombra para dar profundidade visual,
                                                 indicando que a coluna está "flutuando".
                                border-r       → borda direita para separar visualmente
                                                 a coluna fixa das demais.
                            -->
                            <th class="px-6 py-5 sticky left-0 bg-white z-10 border-r border-slate-100 shadow-sm">Fundo
                                / Administrador</th>
                            <th class="px-6 py-5">Identificação (Cedente)</th>
                            <th
                                class="px-6 py-5 text-center text-blue-900 bg-blue-50/30 font-bold border-r border-blue-100">
                                Total Fundo (Mil)</th>

                            <!-- Cabeçalhos dos 8 prazos gerados dinamicamente -->
                            <?php foreach ($labels_prazos as $nome): ?>
                                <th class="px-6 py-5 text-right"><?= $nome ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>

                    <!-- CORPO DA TABELA -->
                    <!--
                        divide-y divide-slate-100 → adiciona uma borda horizontal
                        fina entre cada <tr> dentro do <tbody>, criando a
                        separação visual entre as linhas.
                    -->
                    <tbody class="divide-y divide-slate-100">

                        <?php if (empty($dados)): ?>
                            <!--
                                empty($dados) retorna true se o array estiver vazio
                                (nenhum resultado no banco ou query sem retorno).
                                colspan="11" → mescla todas as 11 colunas da tabela
                                para que a mensagem fique centralizada em linha única.
                            -->
                            <tr>
                                <td colspan="11" class="px-6 py-24 text-center text-slate-400 italic font-medium">
                                    Nenhum dado processado. Por favor, execute o script Python primeiro.
                                </td>
                            </tr>

                        <?php else: ?>
                            <?php foreach ($dados as $row):
                                /*
                                 * VERIFICAÇÃO DE ALERTA POR LINHA
                                 * Para cada cedente, verificamos se sua participação
                                 * ultrapassa 20%. O resultado ($isAlert) é um booleano
                                 * (true/false) usado abaixo para mudar a cor do texto.
                                 */
                                $isAlert = ((float) ($row['PERC_PARTICIPACAO'] ?? 0)) > 20;
                                ?>

                                <!--
                                    CLASSES DE INTERATIVIDADE DA LINHA:
                                    hover:bg-blue-50/30  → ao passar o mouse, fundo fica
                                                           levemente azulado (50% opacidade do blue-50)
                                    transition-all       → anima suavemente todas as mudanças de CSS
                                    group               → marca esta <tr> como "grupo", permitindo que
                                                          filhos usem group-hover: para reagir ao hover
                                                          do pai (ver coluna fixa abaixo)
                                -->
                                <tr class="hover:bg-blue-50/30 transition-all group">

                                    <!-- -----------------------------------------------
                                         COLUNA 1 — NOME DO FUNDO (FIXA NO SCROLL)
                                    ----------------------------------------------- -->
                                    <td
                                        class="px-6 py-4 sticky left-0 bg-white group-hover:bg-blue-50 transition-colors z-10 border-r border-slate-100 shadow-sm">

                                        <!--
                                            group-hover:bg-blue-50 → quando o <tr> pai recebe
                                            hover (grupo), esta célula muda de fundo branco para
                                            azul claro. Necessário porque sticky cells não herdam
                                            o hover do pai automaticamente.

                                            OPERADOR TERNÁRIO para classe condicional:
                                            $isAlert ? 'text-red-700' : 'text-slate-800'
                                            Se cedente em alerta → texto vermelho
                                            Caso contrário       → texto cinza-escuro
                                        -->
                                        <div
                                            class="text-[11px] font-black <?= $isAlert ? 'text-red-700' : 'text-slate-800' ?> uppercase group-hover:text-blue-700 transition-colors leading-tight truncate max-w-xs">
                                            <!--
                                                htmlspecialchars() aqui protege contra XSS:
                                                converte caracteres especiais presentes no nome
                                                do fundo (vindos do banco) em entidades HTML seguras.
                                                ?? 'N/A' → valor padrão se a coluna for null.
                                            -->
                                            <?= htmlspecialchars($row['DENOM_SOCIAL'] ?? 'N/A') ?>
                                        </div>
                                        <!-- Linha secundária: nome do administrador do fundo -->
                                        <div class="text-[9px] text-slate-400 font-semibold mt-1 uppercase italic">Admin:
                                            <?= htmlspecialchars($row['ADMIN'] ?? 'N/A') ?></div>
                                    </td>

                                    <!-- -----------------------------------------------
                                         COLUNA 2 — CNPJ DO CEDENTE
                                         font-mono → fonte monoespaçada, ideal para
                                         identificadores numéricos como CNPJ.
                                    ----------------------------------------------- -->
                                    <td class="px-6 py-4 font-mono text-[10px] text-slate-500 italic">
                                        <?= htmlspecialchars($row['CNPJ_CEDENTE'] ?? '---') ?>
                                    </td>

                                    <!-- -----------------------------------------------
                                         COLUNA 3 — VALOR TOTAL DA CARTEIRA DO FUNDO
                                         Este valor vem da Tabela I da CVM e representa
                                         o total do fundo, não apenas do cedente.
                                    ----------------------------------------------- -->
                                    <td
                                        class="px-6 py-4 text-center font-bold text-slate-600 bg-blue-50/10 border-r border-blue-50">
                                        <?= number_format(((float) ($row['TAB_I2_VL_CARTEIRA'] ?? 0)) / 1000, 0, ',', '.') ?>
                                    </td>

                                    <!-- -----------------------------------------------
                                         COLUNAS 4 a 11 — VALORES POR PRAZO DE VENCIMENTO
                                         Gerados por loop de 1 a 8.
                                         VALOR_CEDENTE_1 = valor proporcional à participação
                                         do cedente que vence em até 30 dias, e assim por diante.
                                    ----------------------------------------------- -->
                                    <?php for ($i = 1; $i <= 8; $i++): ?>
                                        <td
                                            class="px-6 py-4 text-right <?= $i == 1 ? 'bg-blue-50/50 font-bold text-blue-900 border-l border-blue-50 italic' : 'text-slate-600' ?>">
                                            <!--
                                                A primeira coluna (30d) recebe destaque especial
                                                pois representa o risco de liquidez imediato —
                                                o valor que vence mais cedo e exige atenção prioritária.

                                                "VALOR_CEDENTE_$i" com aspas duplas interpola a variável:
                                                quando $i=1, acessa $row['VALOR_CEDENTE_1']
                                                quando $i=2, acessa $row['VALOR_CEDENTE_2'], etc.
                                            -->
                                            <?= number_format(((float) ($row["VALOR_CEDENTE_$i"] ?? 0)) / 1000, 0, ',', '.') ?>
                                        </td>
                                    <?php endfor; ?>

                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </div>


        <!-- ===========================================================
             SEÇÃO 4 — RODAPÉ INSTITUCIONAL
             ===========================================================
             <footer> é um elemento semântico HTML5 para rodapés.
             Contém informações de copyright e status da conexão.
        -->
        <footer
            class="mt-12 pt-6 border-t border-slate-200 flex flex-col md:flex-row justify-between items-center gap-4 text-[9px] font-black text-slate-400 uppercase tracking-widest italic">
            <div class="flex items-center gap-4">
                <span>© 2026 Unidade de Controle de Risco</span>
                <span class="text-slate-300">|</span>
                <span>Dados de Terceiros Processados via CVM</span>
            </div>
            <div class="flex gap-8 items-center">
                <!-- Indicador visual de conexão ativa -->
                <div class="flex items-center gap-2 text-blue-800">
                    <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                    Conexão Ativa: MySQL (XAMPP)
                </div>
            </div>
        </footer>

    </div><!-- fim do container principal -->

</body>
</html>
