import * as React from "react";
import { Input as OVSInput, type InputProps as OVSInputProps } from "@/ovs/primitives/input";

export interface InputProps extends OVSInputProps {}

const Input = React.forwardRef<HTMLInputElement, InputProps>((props, ref) => {
  return <OVSInput ref={ref} {...props} />;
});
Input.displayName = "Input";

export { Input };
