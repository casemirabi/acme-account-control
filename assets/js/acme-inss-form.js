// Função responsável por recarregar a página sem perder a posição de rolagem.
// Antes de dar reload, ela salva o scroll atual no sessionStorage.
function acmeInssSoftReload() {
  try {
    // Pega a posição atual do scroll vertical da janela.
    const currentScrollY = window.scrollY || 0;

    // Salva essa posição para restaurar depois do reload.
    sessionStorage.setItem('acme_inss_scroll_y', String(currentScrollY));
  } catch (error) {
    // Se sessionStorage falhar, ignora o erro silenciosamente.
  }

  // Recarrega a página.
  window.location.reload();
}

// Quando o DOM terminar de carregar,
// tenta restaurar a posição do scroll salva anteriormente.
document.addEventListener('DOMContentLoaded', () => {
  try {
    // Recupera a posição salva no sessionStorage.
    const savedScrollY = Number(sessionStorage.getItem('acme_inss_scroll_y') || '0');

    // Se existir uma posição válida maior que zero, volta para ela.
    if (savedScrollY > 0) {
      window.scrollTo(0, savedScrollY);
    }

    // Remove o valor salvo para não reaplicar em loads futuros.
    sessionStorage.removeItem('acme_inss_scroll_y');
  } catch (error) {
    // Se houver erro ao acessar sessionStorage, ignora.
  }
});

// Função centralizadora da chave usada para salvar o último resultado da consulta.
// Isso evita repetir string fixa em vários lugares.
function acmeInssStorageKey() {
  return 'acme_inss_last_result_v1';
}

// Salva no sessionStorage o último resultado da consulta.
// O payload geralmente contém status, requestId e dados retornados.
function acmeInssSaveLastResult(payload) {
  try {
    sessionStorage.setItem(acmeInssStorageKey(), JSON.stringify(payload));
  } catch (error) {
    // Se não conseguir salvar, ignora silenciosamente.
  }
}

// Carrega do sessionStorage o último resultado salvo.
// Se não existir ou estiver inválido, retorna null.
function acmeInssLoadLastResult() {
  try {
    const rawValue = sessionStorage.getItem(acmeInssStorageKey());

    // Se não existir nada salvo, retorna null.
    if (!rawValue) {
      return null;
    }

    // Converte o JSON salvo de volta para objeto.
    return JSON.parse(rawValue);
  } catch (error) {
    // Se o JSON estiver corrompido ou ocorrer erro de leitura, retorna null.
    return null;
  }
}

// Remove do sessionStorage o último resultado salvo.
function acmeInssClearLastResult() {
  try {
    sessionStorage.removeItem(acmeInssStorageKey());
  } catch (error) {
    // Ignora falha de remoção.
  }
}

// IIFE (Immediately Invoked Function Expression)
// Serve para encapsular toda a lógica e evitar poluir o escopo global.
(function () {
  // Localiza o formulário principal da consulta INSS.
  const form = document.querySelector('.acme-inss-form');

  // Se o formulário não existir na página, encerra a execução.
  if (!form) return;

  // Localiza os elementos essenciais dentro do formulário.
  const input = form.querySelector('.acme-inss-beneficio');
  const button = form.querySelector('.acme-inss-submit');
  const result = form.querySelector('.acme-inss-result');

  // Se algum desses elementos não existir, encerra a execução.
  if (!input || !button || !result) return;

  // Variável usada para armazenar o timer da animação de "pontinhos".
  let acmeInssDotsTimer = null;

  // Função auxiliar que espera determinado tempo usando Promise.
  // Muito usada no loop de polling para aguardar entre uma consulta e outra.
  function sleep(milliseconds) {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
  }

  // Remove tudo que não for número do valor informado.
  // Útil para normalizar número de benefício.
  function onlyDigits(value) {
    return String(value || '').replace(/\D+/g, '');
  }

  // Exibe uma mensagem simples no container de resultado.
  // kind pode ser:
  // ''    => mensagem neutra
  // 'err' => mensagem de erro
  // 'ok'  => mensagem de sucesso
  function renderMessage(message, kind = '') {
    let cssClass = 'acme-inss-msg';

    // Adiciona classes visuais de erro.
    if (kind === 'err') cssClass += ' acme-msg acme-msg-err';

    // Adiciona classes visuais de sucesso.
    if (kind === 'ok') cssClass += ' acme-msg acme-msg-ok';

    // Renderiza o HTML da mensagem no elemento de resultado.
    result.innerHTML = '<div class="' + cssClass + '">' + message + '</div>';
  }

  // Inicia animação com pontos se movendo: "Consultando INSS", "Consultando INSS.", etc.
  function startMovingDots(baseText, kind = '') {
    // Garante que nenhum timer anterior continue rodando.
    stopMovingDots();

    let currentStep = 0;

    // Atualiza a mensagem a cada 350ms.
    acmeInssDotsTimer = setInterval(() => {
      currentStep = (currentStep + 1) % 4;

      // Cria 0 a 3 pontos.
      const dots = '.'.repeat(currentStep);

      // Renderiza a mensagem animada.
      renderMessage(baseText + dots, kind);
    }, 350);
  }

  // Para a animação dos pontos.
  function stopMovingDots() {
    if (acmeInssDotsTimer) {
      clearInterval(acmeInssDotsTimer);
      acmeInssDotsTimer = null;
    }
  }

  // Extrai a melhor mensagem de erro possível de vários formatos de payload.
  // Isso torna o front-end mais resiliente a respostas diferentes da API.
  function pickErrorMessage(payload) {
    // Se não vier payload, usa mensagem padrão.
    if (!payload) return 'Falha ao consultar INSS.';

    // Se o payload já for texto, retorna direto.
    if (typeof payload === 'string') return payload;

    // Se for objeto, tenta achar message/error em vários níveis.
    if (typeof payload === 'object') {
      if (typeof payload.message === 'string' && payload.message.trim()) {
        return payload.message;
      }

      if (typeof payload.error === 'string' && payload.error.trim()) {
        return payload.error;
      }

      if (payload.error && typeof payload.error === 'object') {
        if (typeof payload.error.message === 'string' && payload.error.message.trim()) {
          return payload.error.message;
        }

        if (typeof payload.error.code === 'string' && payload.error.code.trim()) {
          return payload.error.code;
        }
      }
    }

    // Como fallback final, tenta serializar o payload.
    try {
      return JSON.stringify(payload);
    } catch (error) {
      return 'Falha ao consultar INSS.';
    }
  }

  // Renderiza o resultado de sucesso da consulta.
  // Recebe os dados e opcionalmente o requestId.
  function renderSuccess(data, requestId = '') {
    // Alguns retornos vêm em data.response_data, outros já vêm direto em data.
    const responseData = data?.response_data || data || {};

    // "dados" parece ser o núcleo principal do retorno.
    const dados = responseData?.dados || {};

    // Extrai campos com fallback para '-'
    const nome = dados?.nome || '-';
    const beneficio = dados?.beneficio || '-';
    const situacao = dados?.situacao || '-';
    const especie = dados?.especie?.descricao || '-';

    // Booleano convertido para texto amigável.
    const elegivel = dados?.elegivelEmprestimo ? 'Sim' : 'Não';

    // Aqui existe tolerância a nome de campo com possível erro de digitação:
    // bloqueioEmprestimo ou bloqueioEmprestismo
    const bloqueioBruto =
      dados?.bloqueioEmprestimo ??
      dados?.bloqueioEmprestismo ??
      false;

    const bloqueio = bloqueioBruto ? 'Sim' : 'Não';

    const banco = dados?.banco?.descricao || '-';
    const agencia = dados?.agencia || '-';
    const conta = dados?.conta || '-';

    const valorBase = dados?.valorBase ?? '-';
    const margem = dados?.margemConsignavel ?? '-';

    // Variável que vai acumular o HTML dos contratos.
    let contratosHtml = '';

    // Tenta localizar lista de contratos em mais de um ponto da estrutura,
    // porque a API aparentemente pode responder em formatos diferentes.
    const contratosEmprestimo = Array.isArray(responseData?.contratosEmprestimo)
      ? responseData.contratosEmprestimo
      : (Array.isArray(dados?.contratosEmprestimo) ? dados.contratosEmprestimo : []);

    const contratosRMC = Array.isArray(responseData?.contratosRMC)
      ? responseData.contratosRMC
      : (Array.isArray(dados?.contratosRMC) ? dados.contratosRMC : []);

    const contratosRCC = Array.isArray(responseData?.contratosRCC)
      ? responseData.contratosRCC
      : (Array.isArray(dados?.contratosRCC) ? dados.contratosRCC : []);

    // Monta o HTML dos contratos de empréstimo consignado.
    contratosEmprestimo.forEach((contratoData) => {
      contratosHtml += `
        <div class="acme-field acme-inss-contrato">
          <div class="acme-label">${contratoData?.tipoEmprestimo || 'Empréstimo consignado'}</div>
          <div class="acme-value">${contratoData?.contrato || '-'}</div>
          <div class="acme-muted">
            Banco: ${contratoData?.banco?.descricao || '-'}<br>
            Parcelas: ${contratoData?.quantidadeParcelas ?? '-'}<br>
            Parcela: ${contratoData?.valorParcela ?? '-'}<br>
            Situação: ${contratoData?.situacao || '-'}
          </div>
        </div>
      `;
    });

    // Monta o HTML dos contratos RMC.
    contratosRMC.forEach((contratoData) => {
      contratosHtml += `
        <div class="acme-field acme-inss-contrato">
          <div class="acme-label">${contratoData?.tipoEmprestimo || 'RMC'}</div>
          <div class="acme-value">${contratoData?.contrato || '-'}</div>
          <div class="acme-muted">
            Banco: ${contratoData?.banco?.descricao || '-'}<br>
            Limite: ${contratoData?.valorLimiteCartao ?? '-'}<br>
            Reservado: ${contratoData?.valorReservado ?? '-'}<br>
            Situação: ${contratoData?.situacao || '-'}
          </div>
        </div>
      `;
    });

    // Monta o HTML dos contratos RCC.
    contratosRCC.forEach((contratoData) => {
      contratosHtml += `
        <div class="acme-field acme-inss-contrato">
          <div class="acme-label">${contratoData?.tipoEmprestimo || 'RCC'}</div>
          <div class="acme-value">${contratoData?.contrato || '-'}</div>
          <div class="acme-muted">
            Banco: ${contratoData?.banco?.descricao || '-'}<br>
            Limite: ${contratoData?.valorLimiteCartao ?? '-'}<br>
            Reservado: ${contratoData?.valorReservado ?? '-'}<br>
            Situação: ${contratoData?.situacao || '-'}
          </div>
        </div>
      `;
    });

    // Renderiza o card completo com os dados da consulta.
    result.innerHTML = `
      <div class="acme-card">
        <div class="acme-card-h">
          <div class="acme-title">Resultado da Consulta INSS</div>
          <div class="acme-actions">
            <span class="acme-badge ${situacao === 'ATIVO' ? 'acme-badge-ok' : 'acme-badge-bad'}">
              ${situacao || '-'}
            </span>
            <span class="acme-badge ${elegivel === 'Sim' ? 'acme-badge-ok' : 'acme-badge-bad'}">
              Elegível: ${elegivel}
            </span>
          </div>
        </div>

        <div class="acme-card-b">
          <div class="acme-grid">
            <div class="acme-field">
              <div class="acme-label">Nome</div>
              <div class="acme-value">${nome}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Benefício</div>
              <div class="acme-value">${beneficio}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Espécie</div>
              <div class="acme-value">${especie}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Bloqueio</div>
              <div class="acme-value">${bloqueio}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Banco</div>
              <div class="acme-value">${banco}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Agência / Conta</div>
              <div class="acme-value">${agencia} / ${conta}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Valor Base</div>
              <div class="acme-value">${valorBase}</div>
            </div>

            <div class="acme-field">
              <div class="acme-label">Margem</div>
              <div class="acme-value">${margem}</div>
            </div>

          <div class="acme-inss-contracts">
            <div class="acme-title" style="margin-top:14px;">Contratos</div>
            ${contratosHtml || '<div class="acme-msg">Nenhum contrato encontrado.</div>'}
          </div>
        </div>
      </div>
    `;
  }

  // Dispara a consulta inicial para o endpoint REST de "start".
  async function startConsulta(beneficio) {
    const response = await fetch(ACME_INSS.restStart, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': ACME_INSS.restNonce
      },
      body: JSON.stringify({
        beneficio: String(beneficio || '')
      })
    });

    // Lê a resposta como texto primeiro.
    // Isso permite tratar melhor cenários onde o backend não devolve JSON válido.
    const responseText = await response.text();
    let payload = null;

    // Tenta converter o texto em JSON.
    try {
      payload = JSON.parse(responseText);
    } catch (error) {
      payload = null;
    }

    // Se a resposta HTTP não for OK ou o JSON for inválido, lança erro.
    if (!response.ok || !payload) {
      throw new Error(
        pickErrorMessage(payload) ||
        responseText ||
        `HTTP ${response.status}`
      );
    }

    // Mesmo com HTTP 200, a API ainda pode sinalizar success !== true.
    if (payload.success !== true) {
      throw new Error(pickErrorMessage(payload));
    }

    // Retorna o payload validado.
    return payload;
  }

  // Consulta o status de uma requisição já iniciada, usando requestId.
  async function getStatus(requestId) {
    const statusUrl = ACME_INSS.restStatus + '?request_id=' + encodeURIComponent(requestId);

    const response = await fetch(statusUrl, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': ACME_INSS.restNonce
      }
    });

    // Tenta parsear como JSON. Se falhar, retorna null.
    const payload = await response.json().catch(() => null);

    // Se vier inválido ou success !== true, trata como erro.
    if (!payload || payload.success !== true) {
      throw new Error('Erro ao consultar status do INSS.');
    }

    return payload;
  }

  // Extrai o status do payload em vários formatos possíveis.
  function extractStatus(payload) {
    if (!payload) return '';
    if (payload.status) return String(payload.status);
    if (payload.data?.status) return String(payload.data.status);
    if (payload.data?.request?.status) return String(payload.data.request.status);
    if (payload.row?.status) return String(payload.row.status);
    return '';
  }

  // Extrai o request_id do payload em mais de um formato.
  function extractRequestId(payload) {
    return (
      payload?.request_id ||
      payload?.data?.request_id ||
      ''
    );
  }

  // Extrai response_data do payload em mais de um formato.
  function extractResponseData(payload) {
    return (
      payload?.response_data ||
      payload?.data?.response_data ||
      null
    );
  }

  // Função principal executada ao clicar no botão.
  async function run() {
    // Normaliza o valor digitado, mantendo só números.
    const beneficio = onlyDigits(input.value);

    // Validação básica: se não informou benefício, exibe erro e sai.
    if (!beneficio) {
      renderMessage('Informe o número do benefício.', 'err');
      return;
    }

    // Desabilita o botão para evitar múltiplos cliques.
    button.disabled = true;

    // Exibe animação inicial.
    startMovingDots('Consultando INSS');

    try {
      // Inicia a consulta no backend.
      const started = await startConsulta(beneficio);

      // Para a animação momentaneamente.
      stopMovingDots();

      // Extrai requestId e status inicial.
      const requestId = extractRequestId(started);
      const startedStatus = extractStatus(started);

      // Caso o backend já devolva a consulta pronta na primeira resposta.
      if (startedStatus === 'completed' || started?.data?.status === 'completed') {
        const responseData = extractResponseData(started);

        if (responseData) {
          // Renderiza resultado com sucesso.
          renderSuccess({ response_data: responseData }, requestId);

          // Salva em sessionStorage para reapresentar após reload.
          acmeInssSaveLastResult({
            requestId,
            status: 'completed',
            responseData
          });
        } else {
          // Caso a consulta finalize mas sem dados.
          const errorMessage = started?.data?.message || started?.message || 'Consulta finalizada, porém sem dados para exibir.';

          renderMessage(errorMessage, 'err');

          acmeInssSaveLastResult({
            requestId,
            status: 'failed',
            error: errorMessage
          });
        }

        // Recarrega a página mantendo a posição.
        acmeInssSoftReload();
        return;
      }

      // Caso já falhe logo na inicialização.
      if (startedStatus === 'failed') {
        const errorMessage = pickErrorMessage(started?.data?.error || started?.error || started);

        renderMessage(errorMessage, 'err');

        acmeInssSaveLastResult({
          requestId,
          status: 'failed',
          error: errorMessage
        });

        acmeInssSoftReload();
        return;
      }

      // Configurações do polling:
      // máximo 180s esperando e consulta a cada 3s.
      const timeoutMilliseconds = 180000;
      const intervalMilliseconds = 3000;
      const startedAt = Date.now();

      // Troca mensagem para indicar processamento em andamento.
      startMovingDots('Consulta iniciada. Processando');

      // Loop infinito controlado por retornos internos.
      while (true) {
        // Aguarda 3 segundos entre uma checagem e outra.
        await sleep(intervalMilliseconds);

        // Consulta status atual no backend.
        const statusPayload = await getStatus(requestId);
        const currentStatus = extractStatus(statusPayload);

        // Se ainda estiver pendente/processando, continua esperando.
        if (!currentStatus || currentStatus === 'pending' || currentStatus === 'processing') {
          // Se exceder o tempo limite, interrompe e avisa o usuário.
          if (Date.now() - startedAt > timeoutMilliseconds) {
            stopMovingDots();

            renderMessage(
              'Ainda processando...<br>O tempo estimado foi excedido.<br>Atualize a página para consultar novamente.'
            );
            return;
          }

          continue;
        }

        // Se o status final for failed.
        if (currentStatus === 'failed') {
          stopMovingDots();

          const errorMessage = pickErrorMessage(
            statusPayload?.data?.error || statusPayload?.error || statusPayload
          );

          // Salva falha para exibir após reload.
          acmeInssSaveLastResult({
            requestId,
            status: 'failed',
            error: errorMessage
          });

          acmeInssSoftReload();
          return;
        }

        // Se o status final for completed.
        if (currentStatus === 'completed') {
          stopMovingDots();

          const responseData = extractResponseData(statusPayload);

          if (responseData) {
            // Renderiza sucesso.
            renderSuccess({ response_data: responseData }, requestId);

            // Salva sucesso para reapresentação após reload.
            acmeInssSaveLastResult({
              requestId,
              status: 'completed',
              responseData
            });
          } else {
            // Caso complete mas sem dados.
            const errorMessage =
              statusPayload?.data?.message ||
              'Consulta finalizada, porém sem dados para exibir.';

            renderMessage(errorMessage, 'err');

            acmeInssSaveLastResult({
              requestId,
              status: 'failed',
              error: errorMessage
            });
          }

          acmeInssSoftReload();
          return;
        }

        // Se vier qualquer status inesperado, informa erro.
        stopMovingDots();
        renderMessage('Status inesperado: ' + currentStatus, 'err');
        return;
      }
    } catch (error) {
      // Captura qualquer erro do fluxo:
      // falha no fetch, JSON inválido, erro lançado manualmente etc.
      stopMovingDots();

      renderMessage(
        error && error.message ? error.message : 'Erro na requisição.',
        'err'
      );
    } finally {
      // Garante que a animação será parada e o botão reabilitado,
      // independentemente de sucesso ou erro.
      stopMovingDots();
      button.disabled = false;
    }
  }

  // Quando a página carregar, tenta recuperar o último resultado salvo
  // antes do reload e reapresenta para o usuário.
  document.addEventListener('DOMContentLoaded', function () {
    const savedResult = acmeInssLoadLastResult();

    // Se não houver resultado salvo, sai.
    if (!savedResult) return;

    // Se a última consulta terminou com sucesso.
    if (savedResult.status === 'completed') {
      if (savedResult.responseData) {
        renderSuccess({ response_data: savedResult.responseData }, savedResult.requestId || '');
      } else {
        renderMessage('Consulta finalizada, porém sem dados para exibir.', 'err');
      }

      // Limpa o dado salvo depois de exibir.
      acmeInssClearLastResult();
      return;
    }

    // Se a última consulta terminou com erro.
    if (savedResult.status === 'failed') {
      renderMessage(savedResult.error || 'Falha na consulta.', 'err');
      acmeInssClearLastResult();
    }
  });

  // Intercepta o clique do botão.
  button.addEventListener('click', function (event) {
    // Impede comportamento padrão do botão/formulário.
    event.preventDefault();

    // Executa a rotina principal.
    run();
  });
})();