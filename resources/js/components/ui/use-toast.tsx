import { toast, Toaster as Sonner } from "sonner";

const TOAST_LIMIT = 1;
export type ToastProps = React.ComponentProps<typeof toast>;

interface ToastOptions {
  title?: string;
  description?: string;
  variant?: "default" | "destructive";
}

export function useToast() {
  return {
    toast: ({ title, description, variant, ...props }: ToastOptions) => {
      if (variant === "destructive") {
        return toast.error(title, {
          description,
          ...props,
        });
      }

      return toast(title, {
        description,
        ...props,
      });
    },
  };
} 