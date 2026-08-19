<?php
$statusLabels = ['pending'=>'Pendente','pending_payment'=>'Aguardando pagamento','paid'=>'Pago','processing'=>'Em processamento','purchased'=>'Etiqueta comprada','posted'=>'Postado','in_transit'=>'Em trânsito','shipped'=>'Enviado','delivered'=>'Entregue','completed'=>'Concluído','failed'=>'Falhou','exception'=>'Ocorrência','refunded'=>'Estornado','active'=>'Publicado','approved'=>'Aprovado','draft'=>'Rascunho','paused'=>'Pausado','rejected'=>'Correção necessária','archived'=>'Arquivado','blocked'=>'Bloqueado','under_review'=>'Em análise','suspended'=>'Suspenso','cancelled'=>'Cancelado'];
$badgeStatus = $status ?? 'pending';
?><span class="badge badge--<?= e($badgeStatus) ?>"><?= e($statusLabels[$badgeStatus] ?? ucfirst(str_replace('_',' ',$badgeStatus))) ?></span>
