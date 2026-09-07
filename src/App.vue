<template>
  <section v-if="display.download" class="form-section" id="form-section">
    <div class="mediafetch-reset-toolbar">
      <button
        type="button"
        class="mediafetch-reset-button"
        :disabled="resetting"
        @click="resetAllDownloads"
      >
        {{ resetting ? resetWorkingLabel : resetLabel }}
      </button>
    </div>
    <mainForm
      @download="download"
      @search="search"
      @uploadfile="uploadFile"
      :uris="uris"
    ></mainForm>
  </section>
</template>

<script>
import mainForm from "./components/mainForm";
import toggleButton from "./components/toggleButton";
import helper from "./utils/helper";
import { translate as t } from "@nextcloud/l10n";
import contentTable from "./lib/contentTable";

const APP_ID = "mediafetch";

const successCallback = (data) => {
  if (!data) {
    helper.error(t(APP_ID, "Something must have gone wrong!"));
    return;
  }
  if (data.hasOwnProperty("error")) {
    helper.error(t(APP_ID, data.error));
  } else if (data.hasOwnProperty("message")) {
    helper.message(t(APP_ID, data.message));
  } else if (data.hasOwnProperty("file")) {
    helper.message(t(APP_ID, "Downloading" + " " + data.file));
  }
};

export default {
  name: "mainApp",
  inject: ["settings"],
  provide() {
    return {
      search_sites: this.settings.search_sites,
    };
  },
  data() {
    return {
      display: { download: true, search: false },
      resetting: false,
      resetLabel: t(APP_ID, "Stop all downloads & reset"),
      resetWorkingLabel: t(APP_ID, "Stopping downloads…"),
      resetConfirm: t(APP_ID, "Stop all active MediaFetch downloads and clear the live queue? Completed and failed history will be kept."),
      uris: {
        ytd_url: helper.generateUrl("/apps/mediafetch/ytdl/new"),
        aria2_url: helper.generateUrl("/apps/mediafetch/new"),
        search_url: helper.generateUrl("/apps/mediafetch/search"),
        upload_url: helper.generateUrl("/apps/mediafetch/upload"),
        reset_url: helper.generateUrl("/apps/mediafetch/downloads/reset"),
      },
    };
  },
  methods: {
    resetAllDownloads() {
      if (this.resetting || !window.confirm(this.resetConfirm)) {
        return;
      }

      this.resetting = true;
      helper.disablePolling();

      helper.httpClient(this.uris.reset_url)
        .setData({})
        .setHandler((data) => {
          this.resetting = false;
          successCallback(data);

          if (data && Array.isArray(data.warnings) && data.warnings.length > 0) {
            helper.warn(data.warnings.join(" "), 10000);
          }

          contentTable.getInstance().noData();
          helper.getCounters();
        })
        .setErrorHandler(() => {
          this.resetting = false;
          helper.error(t(APP_ID, "Could not reset the download queue."));
        })
        .send();
    },
    download(event) {
      const element = event.target;
      const formWrapper = element.closest("form");
      const formData = helper.getData(formWrapper);
      const inputValue = formData["text-input-value"].trim();
      let message;
      let startYtdlPolling = false;

      if (!helper.isURL(inputValue) && !helper.isMagnetURI(inputValue)) {
        helper.error(t(APP_ID, inputValue + " is Invalid"));
        return;
      }

      if (formData.type === "ytdl") {
        formData["extension"] = "";
        if (formData["select-value-extension"] !== "defaultext") {
          formData["extension"] = formData["select-value-extension"];
        }
        message = helper.t("Download task started!");
        helper.setContentTableType("ytdl-downloads");
        contentTable.getInstance().loading();
        startYtdlPolling = true;
      } else {
        helper.polling();
        helper.setContentTableType("active-downloads");
      }

      if (message) helper.info(message);

      const url = formWrapper.getAttribute("action");
      formData.url = formData["text-input-value"];
      delete formData["text-input-value"];

      helper.httpClient(url)
        .setData(formData)
        .setHandler(function(data) {
          successCallback(data);
        })
        .send();

      if (startYtdlPolling) {
        window.setTimeout(() => helper.pollingYtdl(), 250);
      }
    },
    search(event, vm) {
      const element = event.target;
      const formWrapper = element.closest("form");
      const formData = helper.getData(formWrapper);
      const inputValue = formData["text-input-value"];

      if (!inputValue || inputValue.length < 2) {
        helper.error(t(APP_ID, "Please enter valid keyword!"));
        vm.$data.loading = 0;
        return;
      }

      helper.disablePolling();
      contentTable.getInstance().loading();

      const url = formWrapper.getAttribute("action");
      formData.keyword = formData["text-input-value"];
      formData.site = formData["select-value-search"];
      delete formData["text-input-value"];
      delete formData["select-value-search"];

      helper.httpClient(url)
        .setData(formData)
        .setHandler(function(data) {
          if (data && data.title) {
            vm.$data.loading = 0;
            const tableInst = contentTable.getInstance(data.title, data.row);
            tableInst.actionLink = false;
            tableInst.rowClass = "table-row-search";
            tableInst.create();
          }
          if (data.error) {
            helper.resetSearch(vm);
            helper.error(data.error);
          }
        })
        .send();
    },
    uploadFile(event) {
      const element = event.target;
      const files = element.files || event.dataTransfer.files;
      if (!files) return false;

      const formWrapper = element.closest("form");
      const url = formWrapper.getAttribute("action");
      return helper.httpClient(url)
        .setHandler(function(data) {
          successCallback(data);
        })
        .upload(files[0]);
    },
  },
  components: {
    mainForm,
    toggleButton,
  },
};
</script>

<style lang="scss">
@use "css/variables.scss" as *;

#app-content-wrapper {
  .ncdownloader-form-wrapper {
    position: relative;
    width: 100%;
    top: 0;
    left: 0;
  }
  .ncdownloader-form-wrapper.top-left {
    width: 100%;
    top: 0;
    left: 0;
  }

  .form-section {
    width: 100%;
    display: flex;
    flex-flow: column;
    gap: 1.2em;
  }

  .mediafetch-reset-toolbar {
    display: flex;
    justify-content: flex-end;
  }

  .mediafetch-reset-button {
    border: 1px solid var(--color-error);
    color: var(--color-error-text, var(--color-main-text));
    background: var(--color-main-background);
    font-weight: 600;
  }

  .mediafetch-reset-button:hover:not(:disabled),
  .mediafetch-reset-button:focus-visible:not(:disabled) {
    background: var(--color-error);
    color: var(--color-primary-element-text);
  }
}

@media only screen and (max-width: 1024px) {
  #app-content-wrapper {
    #ncdownloader-form-wrapper {
      position: relative;
      margin: 2px;
    }

    .mediafetch-reset-toolbar {
      justify-content: stretch;
    }

    .mediafetch-reset-button {
      width: 100%;
    }
  }
}
</style>
