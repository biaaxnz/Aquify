"use client";

import { useEffect } from "react";

type FieldDraft = { name: string; type: string; value: string; checked?: boolean };
type FormDraft = FieldDraft[][];

function draftKey() {
  return `aquify:form-draft:${window.location.pathname}`;
}

function eligibleFields(form: HTMLFormElement) {
  return Array.from(form.elements).filter((element): element is HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement =>
    (element instanceof HTMLInputElement || element instanceof HTMLSelectElement || element instanceof HTMLTextAreaElement) &&
    !["hidden", "password", "file", "submit", "button", "reset"].includes(element.type),
  );
}

export function FormDraftRestorer() {
  useEffect(() => {
    const key = draftKey();
    const params = new URLSearchParams(window.location.search);
    if (params.has("message")) {
      window.sessionStorage.removeItem(key);
    } else {
      try {
        const saved = window.sessionStorage.getItem(key);
        if (saved) {
          const drafts = JSON.parse(saved) as FormDraft;
          Array.from(document.forms).forEach((form, formIndex) => {
            drafts[formIndex]?.forEach((draft, fieldIndex) => {
              const field = eligibleFields(form)[fieldIndex];
              if (!field || field.name !== draft.name || field.type !== draft.type) return;
              if (field instanceof HTMLInputElement && ["checkbox", "radio"].includes(field.type)) {
                field.checked = Boolean(draft.checked);
              } else {
                field.value = draft.value;
              }
            });
          });
        }
      } catch (error) {
        console.error("Gagal memulihkan isian formulir.", error);
        window.sessionStorage.removeItem(key);
      }
    }

    const saveDraft = () => {
      try {
        const drafts = Array.from(document.forms).map((form) =>
          eligibleFields(form).map((field) => ({
            name: field.name,
            type: field.type,
            value: field.value,
            ...(field instanceof HTMLInputElement && ["checkbox", "radio"].includes(field.type)
              ? { checked: field.checked }
              : {}),
          })),
        );
        window.sessionStorage.setItem(key, JSON.stringify(drafts));
      } catch (error) {
        console.error("Gagal menyimpan sementara isian formulir.", error);
      }
    };

    document.addEventListener("input", saveDraft);
    document.addEventListener("change", saveDraft);
    return () => {
      document.removeEventListener("input", saveDraft);
      document.removeEventListener("change", saveDraft);
    };
  }, []);

  return null;
}
