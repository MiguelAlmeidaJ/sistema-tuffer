<?php
$steps = ['company' => 'Empresa', 'responsavel' => 'Responsável', 'endereco' => 'Endereço', 'documentos' => 'Documentos', 'review' => 'Revisão'];
$activeStep = $step ?? 'review';
?><nav class="wholesale-steps" aria-label="Etapas do cadastro"><?php foreach ($steps as $key => $label): ?><span class="<?= $key === $activeStep ? 'is-active' : '' ?>"><i><?= array_search($key, array_keys($steps), true) + 1 ?></i><?= e($label) ?></span><?php endforeach; ?></nav>
