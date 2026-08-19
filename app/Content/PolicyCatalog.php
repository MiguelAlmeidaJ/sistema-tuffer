<?php

declare(strict_types=1);

namespace App\Content;

use App\Services\Settings\PlatformSettings;

final class PolicyCatalog
{
    /** @return array<string,array{label:string,description:string}> */
    public static function groups(): array
    {
        return [
            'compradores' => ['label' => 'Para compradores', 'description' => 'Regras de compra, entrega, pagamento, atendimento e relacionamento.'],
            'vendedores' => ['label' => 'Para vendedores', 'description' => 'Condições comerciais e operacionais para vender no marketplace.'],
            'institucional' => ['label' => 'Institucional e legal', 'description' => 'Termos gerais, privacidade, segurança e compromissos da plataforma.'],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function all(?array $settings = null): array
    {
        $settings ??= PlatformSettings::all();
        $platform = trim((string) ($settings['platform_name'] ?? 'Tuffer')) ?: 'Tuffer';
        $operator = trim((string) ($settings['legal_name'] ?? '')) ?: $platform;
        $taxId = trim((string) ($settings['tax_id'] ?? ''));
        $supportEmail = trim((string) ($settings['support_email'] ?? '')) ?: 'contato@tuffer.com.br';
        $privacyEmail = trim((string) ($settings['privacy_email'] ?? '')) ?: $supportEmail;
        $supportPhone = trim((string) ($settings['support_phone'] ?? ''));
        $supportHours = trim((string) ($settings['support_hours'] ?? ''));
        $businessAddress = trim((string) ($settings['business_address'] ?? ''));
        $operatorDescription = $taxId !== '' ? "{$operator}, inscrita no CPF/CNPJ sob nº {$taxId}" : $operator;
        $contactDescription = $supportEmail . ($supportPhone !== '' ? " ou {$supportPhone}" : '') . ($supportHours !== '' ? ", durante {$supportHours}" : '');
        $updated = '21 de julho de 2026';
        $commission = max(0, min(100, (float) ($settings['default_commission'] ?? 10)));
        $sale = 100.00;
        $paymentFee = 4.50;
        $commissionValue = round($sale * ($commission / 100), 2);
        $net = $sale - $commissionValue - $paymentFee;
        $money = static fn(float $value): string => 'R$ ' . number_format($value, 2, ',', '.');

        $policy = static fn(string $title, string $shortTitle, string $group, string $audience, string $summary, array $sections, bool $essential = false, array $references = []): array => [
            'title' => $title,
            'short_title' => $shortTitle,
            'group' => $group,
            'audience' => $audience,
            'summary' => $summary,
            'updated' => $updated,
            'essential' => $essential,
            'sections' => $sections,
            'references' => $references,
        ];
        $section = static fn(string $title, array $paragraphs = [], array $items = [], ?string $note = null, ?array $table = null): array => array_filter([
            'title' => $title,
            'paragraphs' => $paragraphs,
            'items' => $items,
            'note' => $note,
            'table' => $table,
        ], static fn(mixed $value): bool => $value !== null && $value !== []);

        return [
            'termos-de-uso' => $policy(
                'Termos de Uso da Plataforma', 'Termos de Uso', 'institucional', 'Todos os usuários',
                "Regras gerais para acessar e utilizar a {$platform}, incluindo contas, compras, intermediação, responsabilidades e condutas proibidas.",
                [
                    $section('1. Identificação e aceitação', ["A {$platform} é operada por {$operatorDescription}. Estes Termos regulam o acesso ao site, a criação de contas e o uso das funcionalidades do marketplace.", 'Ao criar uma conta, anunciar, comprar ou continuar utilizando a plataforma, o usuário declara ter capacidade legal e concordar com estes Termos e com as políticas relacionadas.']),
                    $section('2. Papel da plataforma e das lojas', ["A {$platform} oferece infraestrutura tecnológica para aproximar compradores e lojas independentes, organizar catálogo, carrinho, pagamento, comunicação e acompanhamento de pedidos.", 'Quando o anúncio identificar uma loja parceira como vendedora, essa loja será responsável pelo produto, informações do anúncio, estoque, documento fiscal, preparação, postagem e atendimento relacionado à venda. Quando a própria Tuffer constar como vendedora, ela assumirá diretamente essas obrigações.', 'A intermediação não elimina os direitos legais do consumidor nem as responsabilidades atribuídas a cada participante pela legislação aplicável.']),
                    $section('3. Cadastro, conta e segurança', ['O cadastro deve conter informações verdadeiras, completas e atualizadas. A conta é pessoal e não pode ser cedida sem autorização.'], ['Manter senha e códigos de acesso em sigilo;', 'Comunicar imediatamente acessos suspeitos;', 'Não criar contas com identidade falsa ou em nome de terceiros sem autorização;', 'Atualizar e-mail, telefone e demais dados relevantes.']),
                    $section('4. Compras e contratação', ['Antes de confirmar o pedido, o comprador poderá revisar produtos, quantidades, loja vendedora, preço, frete, endereço e forma de pagamento. A conclusão depende da aprovação da transação e da disponibilidade de estoque.', 'Pedidos com produtos de lojas diferentes podem gerar cobranças, prazos, documentos fiscais e remessas separados. As regras específicas constam nas políticas de pagamentos, entrega e devolução.']),
                    $section('5. Condutas proibidas', [], ['Praticar fraude, simular transações ou utilizar meios de pagamento sem autorização;', 'Violar direitos de terceiros, publicar conteúdo ilegal ou explorar vulnerabilidades;', 'Interferir no funcionamento da plataforma, coletar dados de forma automatizada sem permissão ou contornar controles de segurança;', 'Assediar usuários, vendedores ou equipe de atendimento;', 'Usar a plataforma para finalidade incompatível com a lei ou com estas políticas.']),
                    $section('6. Suspensão e encerramento', ['A Tuffer poderá limitar funcionalidades, suspender ou encerrar contas diante de fraude, risco à segurança, violação legal ou descumprimento relevante destas regras, preservando pedidos, saldos, defesas e obrigações pendentes quando aplicável.', 'Sempre que possível e seguro, o usuário será informado sobre a medida e poderá apresentar esclarecimentos pelos canais de atendimento.']),
                    $section('7. Propriedade intelectual e disponibilidade', ['Marcas, interface, software e conteúdos próprios da plataforma são protegidos. Conteúdos de vendedores permanecem sob responsabilidade de seus titulares e são licenciados à Tuffer na medida necessária à divulgação dos anúncios.', 'A Tuffer busca manter o serviço disponível e seguro, mas poderá realizar manutenções ou enfrentar indisponibilidades externas. Nada nestes Termos exclui responsabilidade que não possa ser afastada por lei.']),
                    $section('8. Atendimento, alterações e legislação', ["Dúvidas podem ser enviadas para {$contactDescription}." . ($businessAddress !== '' ? " Endereço comercial: {$businessAddress}." : ''), 'As políticas podem ser atualizadas para refletir mudanças legais, técnicas ou operacionais. Alterações relevantes serão comunicadas pelos meios adequados.', 'Aplicam-se as leis da República Federativa do Brasil. O foro será definido conforme a legislação aplicável, preservado o foro do consumidor quando garantido por lei.']),
                ], true,
                [['label' => 'Código de Defesa do Consumidor', 'url' => 'https://www.planalto.gov.br/ccivil_03/leis/l8078compilado.htm'], ['label' => 'Marco Civil da Internet', 'url' => 'https://www.planalto.gov.br/ccivil_03/_ato2011-2014/2014/lei/l12965.htm']]
            ),
            'privacidade' => $policy(
                'Política de Privacidade', 'Privacidade', 'institucional', 'Todos os titulares de dados',
                "Explica como a {$platform} coleta, utiliza, compartilha, protege e conserva dados pessoais, além dos direitos previstos na LGPD.",
                [
                    $section('1. Controlador e abrangência', ["Para os tratamentos relacionados à operação da plataforma, {$operatorDescription} atua como controlador dos dados pessoais. Lojas parceiras também podem atuar como controladoras independentes quando tratam dados para faturamento, entrega, atendimento e obrigações próprias.", "O canal de privacidade é {$privacyEmail}. Esta política abrange visitantes, compradores, vendedores, representantes e usuários das áreas privadas."]),
                    $section('2. Dados coletados', [], ['Identificação e contato: nome, e-mail, telefone, CPF/CNPJ e credenciais;', 'Compra e entrega: endereço, produtos, valores, cupons, pedidos e rastreamento;', 'Vendedores: dados empresariais, fiscais, bancários, societários e documentos de verificação;', 'Uso e segurança: IP, data e hora, dispositivo, navegador, registros de sessão, consentimentos e eventos antifraude;', 'Atendimento e relacionamento: mensagens, reclamações, avaliações e preferências de comunicação;', 'Pagamentos: identificadores, status e dados limitados retornados pelo intermediador; a Tuffer não deve armazenar número completo ou código de segurança do cartão.']),
                    $section('3. Finalidades e bases legais', ['Os dados podem ser tratados para executar contratos e procedimentos preliminares, cumprir obrigações legais ou regulatórias, exercer direitos, prevenir fraudes, proteger crédito, atender interesses legítimos avaliados e, quando necessário, com consentimento.'], ['Criar e proteger contas;', 'Processar pedidos, pagamentos, entregas, devoluções e atendimento;', 'Verificar vendedores e administrar o marketplace;', 'Prevenir abuso, fraude, chargeback e incidentes;', 'Cumprir deveres fiscais, consumeristas, contábeis e judiciais;', 'Enviar comunicações transacionais e, mediante base adequada, campanhas promocionais;', 'Melhorar produtos, desempenho e experiência da plataforma.']),
                    $section('4. Compartilhamento', ['Compartilhamos somente o necessário com lojas envolvidas no pedido, transportadoras e integradores logísticos, meios de pagamento e antifraude, hospedagem, armazenamento de mídia, e-mail, suporte, analytics autorizado, consultores e autoridades quando houver fundamento legal.', 'Cada fornecedor deve receber dados compatíveis com sua função. Transferências internacionais, quando ocorrerem, observarão mecanismos e salvaguardas admitidos pela LGPD.']),
                    $section('5. Retenção e segurança', ['Os dados são mantidos pelo tempo necessário às finalidades informadas e aos prazos legais, regulatórios, contratuais, antifraude e de defesa de direitos. Depois, poderão ser eliminados ou anonimizados.', 'São adotadas medidas administrativas e técnicas proporcionais aos riscos. Nenhum ambiente é absolutamente invulnerável; incidentes relevantes serão tratados e comunicados conforme as exigências aplicáveis.']),
                    $section('6. Direitos do titular', ['O titular pode solicitar confirmação, acesso, correção, anonimização, bloqueio ou eliminação quando cabível, portabilidade nos termos regulamentares, informação sobre compartilhamento, revisão de decisões automatizadas, oposição, revogação do consentimento e informação sobre suas consequências.', "Solicitações devem ser enviadas para {$privacyEmail}. Poderemos pedir confirmação de identidade e manter registros cuja conservação seja autorizada ou exigida por lei."]),
                    $section('7. Crianças, cookies e atualizações', ['A plataforma não é direcionada ao cadastro autônomo de crianças. Dados de menores somente devem ser fornecidos por responsável legal e quando necessários.', 'O uso de cookies segue a Política de Cookies. Esta política pode ser atualizada, com indicação da nova data e comunicação adequada quando a mudança for relevante.']),
                ], true,
                [['label' => 'Lei Geral de Proteção de Dados Pessoais', 'url' => 'https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm'], ['label' => 'Autoridade Nacional de Proteção de Dados', 'url' => 'https://www.gov.br/anpd/pt-br']]
            ),
            'cookies' => $policy(
                'Política de Cookies', 'Cookies', 'institucional', 'Visitantes e usuários',
                'Detalha as categorias de cookies e tecnologias semelhantes, suas finalidades e como controlar preferências.',
                [
                    $section('1. O que são cookies', ['Cookies são pequenos arquivos ou identificadores armazenados no navegador para permitir funções, reconhecer preferências e compreender o uso da plataforma. Tecnologias equivalentes, como armazenamento local e pixels, seguem as mesmas regras quando aplicáveis.']),
                    $section('2. Categorias previstas', ['A presença de cada categoria depende das ferramentas efetivamente habilitadas. A plataforma pode operar apenas com categorias essenciais até que recursos adicionais sejam ativados.'], ['Necessários: viabilizam navegação, carrinho, checkout, preferências de consentimento e recursos essenciais;', 'Segurança e autenticação: mantêm sessões, protegem contas e ajudam a detectar fraude;', 'Preferências: lembram escolhas de interface e experiência;', 'Estatísticas: medem uso e desempenho de forma agregada ou identificável conforme a ferramenta;', 'Marketing e publicidade: apoiam campanhas, atribuição e recomendações personalizadas.']),
                    $section('3. Escolhas e consentimento', ['Cookies estritamente necessários podem funcionar sem consentimento porque sustentam o serviço solicitado e a segurança. Cookies de estatísticas, preferências não essenciais e marketing permanecerão desativados até uma escolha válida quando a legislação exigir.', 'Quando categorias não essenciais forem ativadas, o usuário poderá aceitar, recusar ou alterar escolhas no gerenciador de preferências correspondente. Também pode apagar cookies no navegador, ciente de que isso poderá encerrar sessões e afetar funcionalidades.']),
                    $section('4. Terceiros e duração', ['Alguns cookies podem ser definidos por fornecedores de pagamento, segurança, mídia, atendimento ou mensuração. A duração varia entre a sessão e o prazo informado no gerenciador, devendo ser limitada ao necessário.', 'A relação atualizada de fornecedores e cookies deve ser exibida no painel de preferências quando essas ferramentas forem ativadas.']),
                    $section('5. Contato', ["Dúvidas ou solicitações sobre cookies e dados pessoais podem ser enviadas para {$privacyEmail}."]),
                ], true,
                [['label' => 'Guia de cookies da ANPD', 'url' => 'https://www.gov.br/anpd/pt-br/documentos-e-publicacoes/guia-orientativo-cookies-e-protecao-de-dados-pessoais.pdf']]
            ),
            'trocas-devolucoes-arrependimento' => $policy(
                'Política de Compra, Troca, Devolução e Arrependimento', 'Trocas e devoluções', 'compradores', 'Compradores',
                'Orienta sobre arrependimento, trocas, defeitos, divergências, condições de devolução e reembolso.',
                [
                    $section('1. Direito de arrependimento', ['Nas compras realizadas fora do estabelecimento comercial, o consumidor pode desistir no prazo legal de 7 dias, contado da assinatura ou do recebimento do produto, conforme o caso. A solicitação deve ser registrada por canal disponibilizado no pedido ou pelo atendimento.', 'O exercício regular do arrependimento não será condicionado à apresentação de motivo. A plataforma e a loja orientarão a logística reversa e o reembolso dos valores pagos, inclusive frete, conforme a legislação.']),
                    $section('2. Como solicitar', [], ['Informe número do pedido, item e motivo ou modalidade da solicitação;', 'Envie imagens quando houver defeito, avaria ou divergência;', 'Aguarde as instruções e o código ou procedimento de postagem;', 'Embale o produto com todos os componentes, acessórios e documentos recebidos;', 'Acompanhe a análise pela conta ou pelo canal de atendimento.']),
                    $section('3. Condições e produtos íntimos', ['Para trocas voluntárias por tamanho, cor ou modelo fora de obrigação legal, o item deve estar sem sinais de uso, odores, lavagem, alterações ou danos, com etiquetas, embalagem e lacres de higiene preservados.', 'Em roupas íntimas e itens de uso pessoal, a integridade do lacre pode ser exigida para trocas por preferência e políticas comerciais. Essa regra não afasta o direito legal de arrependimento em compras online nem direitos relacionados a defeito, vício, avaria ou produto diferente do anunciado.'], [], 'Experimente peças íntimas sobre outra roupa e preserve o lacre até decidir pela permanência com o produto.'),
                    $section('4. Defeito, avaria ou divergência', ['Produto defeituoso, avariado, incompleto ou diferente do anúncio deve ser comunicado assim que identificado. A loja poderá solicitar evidências e realizar análise, sem impor barreiras desproporcionais.', 'Os prazos e soluções seguirão o Código de Defesa do Consumidor, incluindo reparo, substituição, abatimento ou restituição quando aplicáveis.']),
                    $section('5. Troca por preferência', ['Trocas por tamanho, cor ou modelo dependem de estoque e das condições comerciais da loja, sem prejuízo do arrependimento legal. Eventual diferença de preço e custo de envio será informada antes da confirmação.']),
                    $section('6. Frete, análise e reembolso', ['Quando a devolução decorrer de arrependimento legal, defeito, avaria ou divergência imputável à loja, o custo da logística reversa será assumido conforme a lei. Em trocas voluntárias adicionais, poderá haver cobrança informada previamente.', 'O reembolso será iniciado após a validação da solicitação ou recebimento e conferência quando essa etapa for necessária. O prazo de visualização depende do método de pagamento, banco e administradora, conforme a Política de Pagamentos e Reembolsos.']),
                    $section('7. Atendimento', ["Solicitações podem ser abertas pela área do pedido ou por {$contactDescription}. A Tuffer poderá mediar o contato com a loja vendedora."]),
                ], true,
                [['label' => 'Código de Defesa do Consumidor', 'url' => 'https://www.planalto.gov.br/ccivil_03/leis/l8078compilado.htm'], ['label' => 'Decreto do Comércio Eletrônico', 'url' => 'https://www.planalto.gov.br/ccivil_03/_ato2011-2014/2013/decreto/d7962.htm']]
            ),
            'entrega-frete' => $policy(
                'Política de Entrega e Frete', 'Entrega e frete', 'compradores', 'Compradores',
                'Explica cálculo, postagem, estimativas, rastreamento e tratamento de atrasos, extravios e avarias.',
                [
                    $section('1. Cálculo e disponibilidade', ['O frete é calculado conforme CEP, origem de cada loja, peso, dimensões, quantidade, transportadora e modalidade disponível. Produtos de lojas diferentes podem gerar fretes e remessas separados.', 'Algumas regiões ou tipos de produto podem não possuir modalidade disponível. O valor final e a estimativa são exibidos antes da conclusão do pedido.']),
                    $section('2. Composição do prazo', ['A previsão total combina o prazo de preparação e postagem da loja com o prazo estimado da transportadora. Datas são estimativas e podem variar por calendário, região, eventos externos e restrições operacionais.'], ['Prazo para a loja preparar e postar;', 'Prazo estimado de trânsito da transportadora;', 'Data ou intervalo previsto de entrega mostrado no pedido.']),
                    $section('3. Rastreamento e tentativas', ['Quando disponível, o código e os eventos de rastreamento serão exibidos na conta. Atualizações dependem da transportadora e podem ocorrer com intervalo.', 'O destinatário deve assegurar acesso ao endereço e pessoa apta a receber. Após tentativas sem sucesso, o pacote poderá retornar à origem e uma nova remessa poderá depender de novo frete quando o impedimento for causado por endereço ou ausência do destinatário.']),
                    $section('4. Endereço incorreto', ['O comprador deve revisar o endereço antes do pagamento. Alterações após a expedição podem ser inviáveis. Se o pacote retornar por informação incorreta ou incompleta, a solução e eventual novo custo serão informados após análise.']),
                    $section('5. Atraso, extravio e avaria', ['Em caso de atraso relevante, a Tuffer e a loja poderão abrir procedimento com a transportadora. Para extravio confirmado, será oferecida solução compatível com a lei, como reenvio ou reembolso.', 'Avarias visíveis devem ser registradas com fotos da embalagem, etiqueta e produto. Quando possível, recuse a entrega de volume claramente violado e comunique o atendimento.']),
                    $section('6. Responsabilidades', ['A loja responde pela preparação correta, embalagem, prazo de postagem e informações de origem. A transportadora responde pela execução do serviço sob sua responsabilidade. A Tuffer organiza informações e poderá intermediar a solução, sem afastar responsabilidades legais.']),
                ], true
            ),
            'pagamentos-reembolsos' => $policy(
                'Política de Pagamentos e Reembolsos', 'Pagamentos e reembolsos', 'compradores', 'Compradores',
                'Regras sobre aprovação, antifraude, parcelamento, cancelamento, estorno e prazos bancários.',
                [
                    $section('1. Formas de pagamento', ['As formas disponíveis são exibidas no checkout e podem incluir cartão, Pix ou outras modalidades habilitadas pelo intermediador. Limites, parcelamento, juros e condições são informados antes da confirmação.', 'A Tuffer utiliza provedores especializados, incluindo Pagar.me quando habilitado. Dados completos do cartão são processados pelo ambiente autorizado do provedor e não devem ser armazenados pela plataforma.']),
                    $section('2. Aprovação, recusa e antifraude', ['A criação do pedido não garante aprovação. O emissor, o intermediador ou mecanismos antifraude podem recusar, revisar ou solicitar informações adicionais.', 'A Tuffer não controla os critérios internos de bancos e bandeiras. Tentativas suspeitas podem ser bloqueadas e contas podem ser limitadas preventivamente, respeitados direitos aplicáveis.']),
                    $section('3. Marketplace e divisão de valores', ['Um carrinho pode conter itens de várias lojas. A plataforma registra os valores por vendedor e pode utilizar divisão de pagamento, recebedores vinculados ou repasses posteriores, conforme a configuração contratual. Essa organização não aumenta o total confirmado pelo comprador.']),
                    $section('4. Cancelamento e reembolso', ['Após cancelamento, arrependimento ou solução de disputa aprovada, o reembolso será solicitado ao intermediador. Em cartão, o crédito pode aparecer na fatura atual ou em faturas seguintes, conforme data de fechamento e regras do emissor.', 'Em Pix ou transferência, a devolução ocorrerá pelo fluxo disponibilizado pelo provedor e poderá exigir validação de titularidade. A Tuffer não promete exibição imediata, pois etapas bancárias fogem ao seu controle.']),
                    $section('5. Retenções e ajustes', ['Transações podem ser retidas durante análise de fraude, chargeback, determinação legal ou divergência relevante. O comprador será orientado quando uma ação for necessária.', 'Reembolsos nunca exigem envio de senha, código de segurança ou pagamento antecipado. Desconfie de contatos fora dos canais oficiais.']),
                    $section('6. Atendimento', ["Dúvidas sobre cobrança e reembolso podem ser encaminhadas para {$contactDescription}, com o número do pedido e sem compartilhar dados completos do cartão."]),
                ], true
            ),
            'termos-vendedores' => $policy(
                'Termos e Condições para Vendedores', 'Termos para vendedores', 'vendedores', 'Vendedores e operadores de loja',
                'Define requisitos, obrigações comerciais, atendimento, catálogo, dados e encerramento de lojas no marketplace.',
                [
                    $section('1. Elegibilidade e cadastro', ['Podem vender pessoas físicas ou jurídicas admitidas pelas regras comerciais vigentes, com capacidade legal, documentação válida e atividade compatível. A Tuffer poderá verificar identidade, CPF/CNPJ, endereço, dados bancários, representação e regularidade cadastral.', 'A aprovação não é automática e poderá ser revista quando houver inconsistência, risco ou alteração relevante.']),
                    $section('2. Responsabilidade comercial e fiscal', ['O vendedor é fornecedor dos produtos identificados em sua loja e responde por origem, autenticidade, segurança, qualidade, conformidade, estoque, preço, descrição, garantias, atendimento e documento fiscal.', 'Cabe ao vendedor cumprir obrigações tributárias, consumeristas, trabalhistas, sanitárias e regulatórias relacionadas à própria operação.']),
                    $section('3. Catálogo, preço e estoque', [], ['Cadastrar somente produtos permitidos e de procedência comprovável;', 'Manter imagens, características, variações, preço, atacado, peso e dimensões verdadeiros;', 'Atualizar estoque e retirar imediatamente itens indisponíveis ou irregulares;', 'Não criar promoções fictícias, avaliações falsas ou anúncios duplicados abusivos;', 'Respeitar marcas, direitos autorais e regras de propriedade intelectual.']),
                    $section('4. Pedidos e atendimento', ['O vendedor deve confirmar e preparar pedidos dentro do prazo indicado, embalar adequadamente, fornecer rastreio e responder comprador e plataforma em tempo razoável.', 'Cancelamentos por falta de estoque, atrasos recorrentes, divergências e ausência de resposta podem afetar o desempenho e gerar medidas previstas nas políticas.']),
                    $section('5. Dados dos compradores', ['Dados recebidos somente podem ser utilizados para executar o pedido, emitir documentos, entregar, atender e cumprir obrigações legais. É proibido formar listas externas, fazer marketing sem base legal, compartilhar ou usar os dados para finalidade incompatível.']),
                    $section('6. Uso da marca e relacionamento', ['A participação no marketplace não cria sociedade, franquia, representação ou vínculo trabalhista. O vendedor não pode se apresentar como representante oficial, salvo autorização escrita.', 'Marcas e materiais da Tuffer só podem ser usados conforme guias e permissões vigentes.']),
                    $section('7. Penalidades e encerramento', ['Violações podem resultar em orientação, retirada de anúncio, retenção compatível com riscos, limitação, suspensão ou encerramento, sem prejuízo de indenização e comunicação às autoridades.', 'Ao encerrar a loja, o vendedor continua responsável por pedidos, devoluções, garantias, tributos, chargebacks, disputas e valores pendentes.']),
                ], true
            ),
            'comissoes-tarifas-recebimentos' => $policy(
                'Política de Comissões, Tarifas e Recebimentos', 'Comissões e recebimentos', 'vendedores', 'Vendedores',
                'Explica formação do valor líquido, comissão, taxas, reservas, repasses, estornos e compensações.',
                [
                    $section('1. Condições comerciais', ["A comissão padrão configurada atualmente na plataforma é de {$commission}%. A condição efetiva de cada loja poderá variar por contrato, categoria, campanha ou negociação e deverá estar visível no painel ou instrumento comercial.", 'Além da comissão, podem existir tarifas do meio de pagamento, antecipação, parcelamento, operação, logística ou serviços opcionais, sempre conforme contratação e informação aplicável.']),
                    $section('2. Exemplo ilustrativo', ['O exemplo abaixo demonstra a composição estimada. A taxa de pagamento é meramente ilustrativa e não substitui o extrato real.'], [], null, [
                        ['Venda do produto', $money($sale)],
                        ["Comissão da plataforma ({$commission}%)", '− ' . $money($commissionValue)],
                        ['Tarifa de pagamento ilustrativa', '− ' . $money($paymentFee)],
                        ['Valor líquido estimado', $money($net)],
                    ]),
                    $section('3. Repasse e antecipação', ['O prazo de repasse considera aprovação, captura, período de segurança, calendário bancário e condição do recebedor no intermediador. Antecipações, quando oferecidas, podem ter custo próprio.', 'O vendedor deve manter dados bancários corretos e de titularidade aceita. Falhas cadastrais podem atrasar repasses.']),
                    $section('4. Cancelamentos, reembolsos e chargebacks', ['Comissões e tarifas podem ser revertidas, mantidas ou recalculadas conforme contrato, momento do cancelamento e custos já incorridos. Valores reembolsados, chargebacks, multas e despesas atribuíveis ao vendedor podem ser descontados de saldos atuais ou futuros.']),
                    $section('5. Reserva e retenções', ['A Tuffer ou o intermediador poderá constituir reserva proporcional ao risco ou reter valores diante de disputa, fraude, obrigação legal, saldo negativo ou indício consistente de descumprimento. A medida deve ser limitada ao necessário e registrada no extrato quando possível.']),
                    $section('6. Extratos e contestação', ['O vendedor deve conferir pedidos, tarifas e repasses no painel. Divergências devem ser comunicadas com documentos de suporte pelo canal indicado, dentro do prazo contratual ou legal aplicável.']),
                ], true
            ),
            'produtos-permitidos-proibidos' => $policy(
                'Política de Produtos Permitidos e Proibidos', 'Produtos permitidos', 'vendedores', 'Vendedores',
                'Define categorias aceitas, itens proibidos, procedência, autenticidade e remoção de anúncios.',
                [
                    $section('1. Escopo permitido', ['A Tuffer poderá limitar o catálogo a moda, vestuário, acessórios e categorias expressamente habilitadas no painel. A existência técnica de uma categoria não garante autorização automática para venda.']),
                    $section('2. Produtos proibidos', [], ['Produtos ilegais, roubados, desviados, falsificados, contrabandeados ou sem procedência;', 'Réplicas não autorizadas e itens que violem marcas, patentes, desenhos ou direitos autorais;', 'Armas, drogas, medicamentos irregulares, conteúdo sexual ilegal ou itens que coloquem pessoas em risco;', 'Produtos usados quando a categoria exigir itens novos, ou itens íntimos usados;', 'Itens recolhidos, vencidos, adulterados ou sem certificação obrigatória;', 'Produtos com alegações enganosas, discriminação, exploração ou violação de direitos.']),
                    $section('3. Procedência e comprovação', ['O vendedor deve conservar notas, contratos, autorizações e registros de origem pelo prazo aplicável e apresentá-los quando solicitados. A ausência de comprovação pode levar à retirada preventiva.']),
                    $section('4. Fiscalização e remoção', ['A Tuffer pode revisar anúncios antes ou depois da publicação, solicitar correção, pausar, remover ou impedir nova oferta. Denúncias serão avaliadas com base em evidências, legislação e risco.', 'Itens ilegais ou perigosos podem ser comunicados às autoridades e gerar suspensão imediata.']),
                    $section('5. Reincidência', ['Reincidência, tentativa de contornar remoções ou uso de contas relacionadas pode resultar em encerramento, retenção de valores compatível com o risco e aplicação das demais medidas previstas.']),
                ], true
            ),
            'anuncios-cadastro-produtos' => $policy(
                'Política de Anúncios e Cadastro de Produtos', 'Cadastro de produtos', 'vendedores', 'Vendedores e equipes de catálogo',
                'Estabelece padrões de imagem, título, descrição, variações, estoque, preço e dados logísticos.',
                [
                    $section('1. Imagens e vídeo', [], ['Utilize imagens nítidas, preferencialmente em 1080 × 1080 pixels e com enquadramento consistente;', 'Mostre o produto real, detalhes relevantes, acabamento e embalagem quando necessário;', 'Não use marcas d’água, contatos externos, preços sobrepostos ou elementos enganosos;', 'Só utilize imagens próprias, licenciadas ou autorizadas;', 'É permitido no máximo um vídeo por produto, compatível com o item anunciado.']),
                    $section('2. Título e descrição', ['O título deve ser objetivo e identificar produto, característica principal, público ou variação útil, sem repetição artificial de palavras.', 'A descrição deve informar material, composição, medidas, conteúdo da embalagem, cuidados, restrições e qualquer característica capaz de influenciar a compra.']),
                    $section('3. Variações, estoque e identificação', [], ['Cadastre corretamente tamanhos, cores, modelos e SKU;', 'Mantenha estoque disponível e reserva coerentes;', 'Informe código de barras quando existente ou exigido;', 'Não concentre produtos diferentes como variações para manipular relevância;', 'Evite anúncios duplicados do mesmo produto na mesma loja.']),
                    $section('4. Preço, atacado e promoções', ['Preço de varejo, preço promocional, atacado, quantidade mínima e possibilidade de misturar variações devem ser verdadeiros e consistentes.', 'É proibido elevar artificialmente o preço anterior, ocultar condições ou anunciar desconto sem referência legítima.']),
                    $section('5. Dados de envio', ['Peso, largura, altura, comprimento e origem devem refletir o pacote pronto para envio. Dados incorretos podem causar cobrança adicional, devolução e penalidade.']),
                    $section('6. Correção e moderação', ['A Tuffer poderá solicitar correções com prazo proporcional ao risco. Anúncios graves, ilegais, falsificados ou capazes de prejudicar consumidores podem ser removidos imediatamente.']),
                ], true
            ),
            'desempenho-penalidades-vendedor' => $policy(
                'Política de Desempenho e Penalidades do Vendedor', 'Desempenho e penalidades', 'vendedores', 'Vendedores',
                'Apresenta indicadores acompanhados e medidas graduais para proteger compradores e a qualidade do marketplace.',
                [
                    $section('1. Indicadores', [], ['Cancelamentos causados pela loja;', 'Postagens atrasadas e pedidos sem atualização;', 'Produtos vendidos sem estoque;', 'Tempo de resposta e reclamações não resolvidas;', 'Divergência entre produto e anúncio;', 'Índice e motivos de devolução;', 'Autenticidade, avaliações e incidentes de segurança.']),
                    $section('2. Análise contextual', ['Os indicadores serão analisados considerando volume, gravidade, reincidência, sazonalidade, evidências e fatores externos. Uma ocorrência isolada não gera automaticamente a medida máxima, salvo risco grave ou ilegalidade.']),
                    $section('3. Medidas graduais', [], ['Nível 1 — orientação e plano de correção;', 'Nível 2 — advertência formal;', 'Nível 3 — limitação de anúncios, campanhas ou funcionalidades;', 'Nível 4 — suspensão temporária e revisão da operação;', 'Nível 5 — encerramento da conta e medidas adicionais cabíveis.']),
                    $section('4. Medidas imediatas', ['Fraude, falsificação, risco à saúde ou segurança, desvio de valores, violação grave de dados ou tentativa de burlar controles pode justificar suspensão cautelar imediata.']),
                    $section('5. Comunicação e revisão', ['Sempre que compatível com a segurança e a investigação, a loja será informada sobre o motivo e poderá apresentar documentos. A Tuffer poderá manter a medida enquanto persistir o risco ou a obrigação legal.']),
                ]
            ),
            'chargeback-fraude-contestacao' => $policy(
                'Política de Chargeback, Fraude e Contestação', 'Chargeback e fraude', 'vendedores', 'Vendedores',
                'Define responsabilidades, evidências, defesa, retenção de recebíveis e efeitos das decisões do meio de pagamento.',
                [
                    $section('1. Conceitos', ['Chargeback é a contestação de uma transação pelo titular junto ao emissor ou arranjo de pagamento. Pode decorrer de fraude, desacordo comercial, duplicidade, não reconhecimento ou falha no fornecimento.']),
                    $section('2. Comunicação e defesa', ['Ao receber uma contestação, a Tuffer poderá solicitar resposta dentro do prazo informado pelo intermediador. O vendedor deve enviar evidências legíveis e autênticas.'], ['Documento fiscal;', 'Comprovante de postagem e entrega;', 'Dados do anúncio e aceite do pedido;', 'Comunicações com o comprador;', 'Fotos do produto e embalagem;', 'Outros registros relacionados ao motivo da contestação.']),
                    $section('3. Responsabilidade financeira', ['O vendedor poderá ser responsabilizado quando a contestação decorrer de não envio, divergência, produto irregular, atendimento insuficiente ou descumprimento atribuível à loja. Fraudes externas serão analisadas conforme contrato, evidências e regras do intermediador.']),
                    $section('4. Retenção e compensação', ['Recebíveis podem ser reservados durante a disputa e valores perdidos podem ser descontados de saldos presentes ou futuros, respeitadas as condições comerciais e legais.']),
                    $section('5. Decisão', ['A decisão final do banco, bandeira ou intermediador pode prevalecer sobre a análise interna. A Tuffer informará o resultado disponível, sem garantir êxito da defesa. Fraude do comprador ou vendedor poderá gerar bloqueio e comunicação às autoridades.']),
                ]
            ),
            'fiscal-vendedores' => $policy(
                'Política Fiscal para Vendedores', 'Política fiscal', 'vendedores', 'Vendedores',
                'Esclarece a responsabilidade do vendedor por cadastro, tributos, documentos fiscais e guarda de registros.',
                [
                    $section('1. Responsabilidade do vendedor', ['Cada vendedor é responsável por avaliar seu enquadramento e cumprir obrigações municipais, estaduais e federais relacionadas às vendas, inclusive inscrições, licenças e tributos.']),
                    $section('2. Documentos fiscais', ['O vendedor deve emitir nota fiscal ou documento equivalente quando exigido, com dados corretos do produto, comprador, operação, tributos e transporte, encaminhando-o conforme a legislação.']),
                    $section('3. Informações dos produtos', ['NCM, origem, CEST, alíquotas, unidade e demais classificações, quando exigidas, devem ser definidas pelo vendedor com orientação profissional. A Tuffer poderá disponibilizar campos, mas não valida o enquadramento tributário.']),
                    $section('4. Guarda e fiscalização', ['Documentos e registros devem ser mantidos pelo prazo legal e apresentados às autoridades ou à Tuffer quando necessários à apuração de pedido, pagamento ou irregularidade.']),
                    $section('5. Ausência de consultoria', ['A plataforma não presta consultoria contábil, fiscal ou jurídica. O vendedor deve consultar contador ou advogado para validar sua situação específica.']),
                ]
            ),
            'avaliacoes-comentarios' => $policy(
                'Política de Avaliações e Comentários', 'Avaliações e comentários', 'compradores', 'Compradores e vendedores',
                'Regras para avaliações autênticas, respostas de vendedores e moderação de conteúdo.',
                [
                    $section('1. Quem pode avaliar', ['A avaliação poderá ser disponibilizada a compradores vinculados a uma compra ou interação verificável, dentro do prazo apresentado na conta.']),
                    $section('2. Conteúdo permitido', ['Relate experiência real com produto, entrega ou atendimento, de forma objetiva e respeitosa. Críticas negativas são permitidas e não serão removidas apenas por desagradar à loja.']),
                    $section('3. Conteúdo proibido', [], ['Ofensas, ameaças, discriminação ou assédio;', 'Dados pessoais, contatos, links maliciosos ou conteúdo ilegal;', 'Avaliações falsas, coordenadas ou referentes a produto não adquirido;', 'Spam, propaganda, chantagem ou incentivo condicionado a avaliação positiva;', 'Imagens ou textos que violem direitos de terceiros.']),
                    $section('4. Moderação e resposta', ['A Tuffer pode ocultar, editar dados pessoais aparentes ou remover conteúdo incompatível, preservando evidências quando necessário. O vendedor poderá responder sem intimidar o comprador ou divulgar seus dados.']),
                    $section('5. Fraude e reanálise', ['Manipulação de avaliações pode resultar em remoção, perda de benefícios e penalidades. Usuários podem solicitar reanálise pelo atendimento, indicando o conteúdo e o motivo.']),
                ]
            ),
            'atendimento-reclamacoes-disputas' => $policy(
                'Política de Atendimento, Reclamações e Disputas', 'Atendimento e disputas', 'compradores', 'Compradores e vendedores',
                'Organiza os canais, etapas, provas e possíveis resultados de reclamações relacionadas aos pedidos.',
                [
                    $section('1. Canais e prazo inicial', ["O atendimento pode ser acionado pela área da conta ou por {$contactDescription}. O prazo inicial estimado será informado no canal e pode variar conforme complexidade, volume e participação de terceiros."]),
                    $section('2. Fluxo de solução', [], ['1. O comprador contata a loja pelo canal do pedido;', '2. A loja analisa e responde no prazo informado;', '3. Sem solução, o comprador solicita mediação da Tuffer;', '4. A plataforma reúne pedido, comunicação e provas das partes;', '5. O caso pode resultar em continuidade, correção, devolução, reembolso, encerramento ou outra solução cabível.']),
                    $section('3. Informações necessárias', ['Podem ser solicitados número do pedido, descrição objetiva, fotos, vídeos, embalagem, etiqueta, documento de postagem, laudo ou comprovante compatível com o problema. Solicite e envie apenas o necessário.']),
                    $section('4. Mediação', ['A Tuffer atuará de boa-fé na organização da disputa, mas não substitui autoridades, órgãos de defesa do consumidor, arbitragem obrigatoriamente aceita ou Poder Judiciário. Direitos legais permanecem preservados.']),
                    $section('5. Resultado e reanálise', ['A decisão considerará legislação, anúncio, histórico, rastreio, documentos e responsabilidades. Uma reanálise poderá ser solicitada quando houver fato novo ou erro material, dentro do prazo informado.']),
                ]
            ),
            'seguranca-informacao' => $policy(
                'Política de Segurança da Informação', 'Segurança', 'institucional', 'Todos os usuários',
                'Apresenta compromissos públicos de proteção de contas, prevenção de fraude, comunicação e resposta a incidentes.',
                [
                    $section('1. Proteção da plataforma', ['A Tuffer adota controles proporcionais aos riscos para autenticação, acesso, registros, atualizações, continuidade e tratamento de dados, sem divulgar detalhes que comprometam sua eficácia.']),
                    $section('2. Responsabilidade do usuário', [], ['Use senha exclusiva e mantenha e-mail e dispositivo protegidos;', 'Não compartilhe códigos, links de acesso ou sessão;', 'Encerre o acesso em dispositivos compartilhados;', 'Revise remetente e endereço antes de clicar ou pagar;', 'Comunique imediatamente atividade suspeita.']),
                    $section('3. Fraude e phishing', ['A Tuffer não solicita senha, código de cartão, código de autenticação ou pagamento para liberar reembolso. Mensagens suspeitas devem ser ignoradas e reportadas pelos canais oficiais.']),
                    $section('4. Incidentes', ['Eventos de segurança serão investigados, contidos e documentados. Titulares e autoridades serão comunicados quando exigido e nos termos aplicáveis, com orientações úteis sem prejudicar a investigação.']),
                    $section('5. Vulnerabilidades', ["Relatos responsáveis de vulnerabilidade podem ser enviados para {$supportEmail}, com descrição, impacto e forma segura de reprodução. Não acesse dados de terceiros, não interrompa o serviço e não publique detalhes antes da correção."]),
                ], false,
                [['label' => 'Marco Civil da Internet', 'url' => 'https://www.planalto.gov.br/ccivil_03/_ato2011-2014/2014/lei/l12965.htm']]
            ),
            'propriedade-intelectual' => $policy(
                'Política de Propriedade Intelectual', 'Propriedade intelectual', 'institucional', 'Titulares de direitos e usuários',
                'Protege marcas, software, textos, imagens e conteúdos, e estabelece o procedimento de denúncia e remoção.',
                [
                    $section('1. Ativos da plataforma', ["A marca {$platform}, logotipos, interface, código, textos institucionais, seleção e organização de conteúdo pertencem a seus titulares ou licenciantes. O uso não autorizado é proibido."]),
                    $section('2. Conteúdo dos vendedores', ['O vendedor conserva os direitos sobre conteúdo próprio e concede à Tuffer licença não exclusiva, gratuita, válida durante a oferta e pelo período necessário a registros e defesa de direitos, para hospedar, adaptar tecnicamente, exibir e divulgar o anúncio.']),
                    $section('3. Garantias do anunciante', ['Quem envia conteúdo declara possuir direitos ou autorização e responde por imagens, marcas, descrições, vídeos e materiais de terceiros. Produtos falsificados e uso indevido de marca são proibidos.']),
                    $section('4. Denúncia', ["Titulares podem enviar denúncia para {$supportEmail}, identificando direito, conteúdo, URL, titularidade, contato e declaração de boa-fé. Informações incompletas podem exigir complementação."]),
                    $section('5. Remoção e reincidência', ['Conteúdo poderá ser removido preventivamente conforme evidências e risco. O anunciante poderá apresentar autorização ou contestação. Violações repetidas podem gerar encerramento da loja e preservação de registros.']),
                ]
            ),
            'comunicacoes-marketing' => $policy(
                'Política de Comunicações e Marketing', 'Comunicações e marketing', 'institucional', 'Usuários e assinantes',
                'Explica mensagens transacionais, campanhas, canais utilizados, personalização e cancelamento.',
                [
                    $section('1. Comunicações essenciais', ['Confirmações de conta, segurança, pedido, pagamento, entrega, devolução, suporte e mudanças relevantes são transacionais e podem ser enviadas enquanto necessárias à relação contratual ou obrigação legal.']),
                    $section('2. Comunicações promocionais', ['Ofertas, novidades, carrinho abandonado e recomendações podem ser enviadas por e-mail, WhatsApp, SMS ou notificação quando houver base legal adequada e respeito às preferências registradas.']),
                    $section('3. Personalização', ['Recomendações podem considerar navegação, favoritos, compras e preferências. Quando depender de cookies não essenciais ou consentimento, a escolha será respeitada.']),
                    $section('4. Cancelamento', ['O usuário pode sair de campanhas pelo link de descadastro ou pelo atendimento. O pedido será processado em prazo razoável, sem impedir mensagens essenciais sobre conta, segurança e pedidos em andamento.']),
                    $section('5. Canais oficiais', ["Dúvidas podem ser enviadas para {$supportEmail}. Nunca forneça senha ou códigos de pagamento em resposta a campanhas."]),
                ]
            ),
            'encerramento-exclusao-conta' => $policy(
                'Política de Encerramento e Exclusão de Conta', 'Exclusão de conta', 'institucional', 'Clientes, vendedores e operadores',
                'Explica solicitação de exclusão, pendências, retenção legal, anonimização, suspensão e reativação.',
                [
                    $section('1. Solicitação do usuário', ["A exclusão pode ser solicitada pela área da conta ou por {$privacyEmail}. A Tuffer poderá confirmar identidade e informar consequências antes de concluir."]),
                    $section('2. Pedidos e valores pendentes', ['Pedidos em andamento, devoluções, garantias, disputas, recebíveis, chargebacks ou saldos devem ser concluídos ou preservados antes do encerramento definitivo das funções relacionadas.']),
                    $section('3. Retenção e anonimização', ['Excluir a conta não significa apagar imediatamente todos os registros. Dados podem ser mantidos para obrigações fiscais, consumeristas, contábeis, regulatórias, prevenção a fraude e defesa de direitos. Quando possível, dados sem necessidade de identificação serão eliminados ou anonimizados.']),
                    $section('4. Suspensão por iniciativa da plataforma', ['Fraude, risco, ordem legal ou violação de políticas pode gerar suspensão ou encerramento. O acesso aos dados poderá ser limitado enquanto registros necessários forem preservados.']),
                    $section('5. Lojas e operadores', ['O encerramento de loja não extingue responsabilidade por pedidos, tributos, documentos, garantias e valores. Operadores perdem acesso quando o vínculo é removido pelo vendedor ou pela plataforma.']),
                    $section('6. Reativação', ['A reativação poderá ser possível se a conta não tiver sido definitivamente eliminada, se a causa da suspensão tiver sido resolvida e se os requisitos atuais forem atendidos. Não há garantia de restauração de anúncios ou benefícios.']),
                ]
            ),
            'acessibilidade' => $policy(
                'Aviso de Acessibilidade', 'Acessibilidade', 'institucional', 'Todos os usuários',
                'Apresenta o compromisso da Tuffer com uma experiência digital mais acessível e o canal para relatar barreiras.',
                [
                    $section('1. Compromisso', ['A Tuffer busca evoluir seus serviços para que pessoas com diferentes habilidades, tecnologias e contextos possam navegar, compreender e concluir tarefas com autonomia.']),
                    $section('2. Práticas adotadas', [], ['Estrutura semântica e navegação por teclado;', 'Contraste e indicação de foco em componentes interativos;', 'Textos alternativos para imagens relevantes;', 'Labels, mensagens e instruções associadas a formulários;', 'Compatibilidade progressiva com leitores de tela e ampliação;', 'Respeito à preferência por movimento reduzido.']),
                    $section('3. Limitações e terceiros', ['Alguns conteúdos enviados por vendedores ou componentes de fornecedores externos podem apresentar limitações. A Tuffer buscará orientar correções e oferecer alternativa razoável quando possível.']),
                    $section('4. Como comunicar uma barreira', ["Envie para {$supportEmail} a página, tarefa desejada, tecnologia assistiva utilizada e descrição da dificuldade. Não é necessário informar condição de saúde."]),
                    $section('5. Melhoria contínua', ['Relatos são usados para priorizar correções, testes e evolução do design. Este aviso será atualizado conforme novas práticas forem incorporadas.']),
                ]
            ),
        ];
    }
}
