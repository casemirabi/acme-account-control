document.addEventListener("DOMContentLoaded", function () {
  const form = document.querySelector(".acme-inss-form");
  if (!form) return;

  const input = form.querySelector(".acme-inss-beneficio");
  const button = form.querySelector(".acme-inss-submit");
  const result = form.querySelector(".acme-inss-result");

  if (!input || !button || !result) return;

  function renderMessage(message) {
    result.innerHTML = '<div class="acme-inss-msg">' + message + "</div>";
  }

  function renderSuccess(data) {
    const responseData = data?.response_data || {};
    const dados = responseData?.dados || {};

    const nome = dados?.nome || "-";
    const beneficio = dados?.beneficio || "-";
    const situacao = dados?.situacao || "-";
    const especie = dados?.especie?.descricao || "-";
    const elegivel = dados?.elegivelEmprestimo ? "Sim" : "Não";

    const bloqueioBruto =
      dados?.bloqueioEmprestimo ??
      dados?.bloqueioEmprestismo ??
      false;

    const bloqueio = bloqueioBruto ? "Sim" : "Não";

    const banco = dados?.banco?.descricao || "-";
    const agencia = dados?.agencia || "-";
    const conta = dados?.conta || "-";

    const valorBase = dados?.valorBase ?? "-";
    const margem = dados?.margemConsignavel ?? "-";

    let contratosHtml = "";

    const contratosEmprestimo = Array.isArray(responseData?.contratosEmprestimo)
      ? responseData.contratosEmprestimo
      : (Array.isArray(dados?.contratosEmprestimo) ? dados.contratosEmprestimo : []);

    const contratosRMC = Array.isArray(responseData?.contratosRMC)
      ? responseData.contratosRMC
      : (Array.isArray(dados?.contratosRMC) ? dados.contratosRMC : []);

    const contratosRCC = Array.isArray(responseData?.contratosRCC)
      ? responseData.contratosRCC
      : (Array.isArray(dados?.contratosRCC) ? dados.contratosRCC : []);

    contratosEmprestimo.forEach((contratoData) => {
      contratosHtml += `
        <div class="acme-field acme-inss-contrato">
          <div class="acme-label">${contratoData?.tipoEmprestimo || "Empréstimo consignado"}</div>
          <div class="acme-value">${contratoData?.contrato || "-"}</div>
          <div class="acme-muted">
            Banco: ${contratoData?.banco?.descricao || "-"}<br>
            Parcelas: ${contratoData?.quantidadeParcelas ?? "-"}<br>
            Parcela: ${contratoData?.valorParcela ?? "-"}<br>
            Situação: ${contratoData?.situacao || "-"}
          </div>
        </div>
      `;
    });

    contratosRMC.forEach((contratoData) => {
      contratosHtml += `
        <div class="acme-field acme-inss-contrato">
          <div class="acme-label">${contratoData?.tipoEmprestimo || "RMC"}</div>
          <div class="acme-value">${contratoData?.contrato || "-"}</div>
          <div class="acme-muted">
            Banco: ${contratoData?.banco?.descricao || "-"}<br>
            Limite: ${contratoData?.valorLimiteCartao ?? "-"}<br>
            Reservado: ${contratoData?.valorReservado ?? "-"}<br>
            Situação: ${contratoData?.situacao || "-"}
          </div>
        </div>
      `;
    });

    contratosRCC.forEach((contratoData) => {
      contratosHtml += `
        <div class="acme-field acme-inss-contrato">
          <div class="acme-label">${contratoData?.tipoEmprestimo || "RCC"}</div>
          <div class="acme-value">${contratoData?.contrato || "-"}</div>
          <div class="acme-muted">
            Banco: ${contratoData?.banco?.descricao || "-"}<br>
            Limite: ${contratoData?.valorLimiteCartao ?? "-"}<br>
            Reservado: ${contratoData?.valorReservado ?? "-"}<br>
            Situação: ${contratoData?.situacao || "-"}
          </div>
        </div>
      `;
    });

    result.innerHTML = `
      <div class="acme-card">
        <div class="acme-card-h">
          <div class="acme-title">Resultado da Consulta INSS</div>
          <div class="acme-actions">
            <span class="acme-badge ${situacao === "ATIVO" ? "acme-badge-ok" : "acme-badge-bad"}">
              ${situacao || "-"}
            </span>
            <span class="acme-badge ${elegivel === "Sim" ? "acme-badge-ok" : "acme-badge-bad"}">
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
          </div>

          <div class="acme-inss-contracts">
            <div class="acme-title" style="margin-top:14px;">Contratos</div>
            ${contratosHtml || '<div class="acme-msg">Nenhum contrato encontrado.</div>'}
          </div>
        </div>
      </div>
    `;
  }

  button.addEventListener("click", async function () {
    const beneficio = (input.value || "").replace(/\D/g, "");

    if (!beneficio) {
      renderMessage("Informe o número do benefício.");
      return;
    }

    renderMessage("Consultando INSS...");

    try {
      const response = await fetch(ACME_INSS.restStart, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": ACME_INSS.restNonce
        },
        body: JSON.stringify({
          beneficio: beneficio
        })
      });

      const payload = await response.json();

      if (!response.ok || !payload.success) {
        const message =
          payload?.error?.message ||
          payload?.message ||
          "Falha ao consultar INSS.";

        renderMessage(message);
        return;
      }

      renderSuccess(payload.data || {});
    } catch (error) {
      console.error("ACME INSS fetch error:", error);
      renderMessage("Erro na requisição.");
    }
  });
});